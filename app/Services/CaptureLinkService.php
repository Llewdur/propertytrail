<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CaptureLink;
use App\Models\EvidenceLink;
use App\Models\Lease;
use App\Repositories\CaptureNoteRepository;
use App\Support\CaptureTime;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CaptureLinkService
{
    public const LINK_TTL_DAYS = 14;

    public const MAX_PHOTOS = 500;

    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
        private readonly EvidenceIngestionService $ingestion,
        private readonly CaptureRoomService $rooms,
        private readonly CaptureNoteRepository $notes,
    ) {}

    /**
     * Issues the lease's one live link, revoking any earlier one. The plain
     * token is returned once; it is kept only encrypted, for reminders.
     *
     * @return array{link: CaptureLink, url: string}
     */
    public function issue(Lease $lease, string $phase, ?int $userId): array
    {
        return DB::transaction(function () use ($lease, $phase, $userId): array {
            Lease::query()->lockForUpdate()->find($lease->getKey());
            $this->revoke($lease, $userId);

            $plain = Str::random(48);
            $link = CaptureLink::query()->create([
                'organisation_id' => $lease->organisation_id,
                'lease_id' => $lease->getKey(),
                'phase' => $phase,
                'token_hash' => CaptureLink::hash($plain),
                'token_encrypted' => $plain,
                'expires_at' => now()->addDays(self::LINK_TTL_DAYS),
                'created_by_user_id' => $userId,
            ]);
            $this->audit($lease, 'capture_link_issued', $userId, ['capture_link_id' => $link->id, 'phase' => $phase]);

            return ['link' => $link, 'url' => self::url($plain)];
        });
    }

    /** In the fragment, which browsers never send: the token stays out of server, proxy and referrer logs. */
    public static function url(#[\SensitiveParameter] string $plainToken): string
    {
        return url('/capture') . '#' . $plainToken;
    }

    public function revoke(Lease $lease, ?int $userId): int
    {
        $revoked = CaptureLink::query()
            ->where('lease_id', $lease->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($revoked > 0) {
            $this->audit($lease, 'capture_link_revoked', $userId, ['count' => $revoked]);
        }

        return $revoked;
    }

    public function current(Lease $lease): ?CaptureLink
    {
        return CaptureLink::query()
            ->where('lease_id', $lease->getKey())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();
    }

    /**
     * Finds a public link by its token. A live link binds the request to its own
     * organisation, derived server-side from the hash.
     */
    public function resolve(#[\SensitiveParameter] string $plainToken): ?CaptureLink
    {
        $link = $plainToken === ''
            ? null
            : CaptureLink::query()->allOrganisations()->where('token_hash', CaptureLink::hash($plainToken))->first();

        if ($link !== null && $link->isLive()) {
            $this->currentOrganisation->set($link->organisation_id);
            $link->markUsed();
        }

        return $link;
    }

    /**
     * Photos the tenant captured for this lease and phase, through this link or
     * an earlier one. Staff uploads are never included.
     *
     * @return Builder<EvidenceLink>
     */
    public function photos(CaptureLink $link): Builder
    {
        return EvidenceLink::query()
            ->with('evidenceRecord')
            ->where('attachable_type', new Lease()->getMorphClass())
            ->where('attachable_id', $link->lease_id)
            ->where('phase', $link->phase)
            ->whereIn('capture_link_id', CaptureLink::query()->where('lease_id', $link->lease_id)->select('id'));
    }

    /**
     * Saves one photo to the room it names, creating the tenant's new room with
     * its first photo. A retried upload of the same bytes to the same room returns
     * the photo already held. Null means the lease does not cover the room.
     *
     * @param array<string, mixed> $data validated capture fields
     */
    public function capture(CaptureLink $link, Lease $lease, UploadedFile $file, array $data): ?EvidenceLink
    {
        /** @var EvidenceLink|null $captured */
        $captured = DB::transaction(function () use ($link, $lease, $file, $data): ?EvidenceLink {
            // Locking the lease serialises two uploads creating the same room or saving the same photo.
            Lease::query()->lockForUpdate()->find($link->lease_id);
            $areaId = $this->rooms->roomId($lease, $data);

            if ($areaId === null) {
                return null;
            }

            $hash = hash('sha256', (string) $file->get());
            $held = $this
                ->photos($link)
                ->where('area_room_id', $areaId)
                ->whereHas('evidenceRecord', static fn(Builder $record) => $record->where('sha256_hash', $hash))
                ->first();

            $issue = $this->notes->findIssue($link, (string) ($data['issue_key'] ?? ''));

            if ($held instanceof EvidenceLink) {
                if ($issue !== null && $held->capture_issue_id === null) {
                    $held->forceFill(['capture_issue_id' => $issue->id])->save();
                }

                return $held;
            }

            $corner = ($data['corner'] ?? null) !== null ? (int) $data['corner'] : null;
            $shot = ($data['shot'] ?? null) !== null ? (string) $data['shot'] : null;

            $label = $issue === null ? $this->shotLabel($shot, $corner) : 'Close-up: ' . ucfirst($issue->kind);

            $record = $this->ingestion->ingest($file, Lease::class, $link->lease_id, $label, [
                'phase' => $link->phase,
                'area_room_id' => $areaId,
                'taken_at' => CaptureTime::from($data['taken_at'] ?? null),
            ]);

            /** @var EvidenceLink $photo */
            $photo = $record->links()->latest('id')->firstOrFail();
            $photo->forceFill([
                'capture_link_id' => $link->id,
                'capture_corner' => $corner,
                'capture_shot' => $shot,
                'capture_issue_id' => $issue?->id,
            ])->save();

            return $photo;
        });

        return $captured;
    }

    private function shotLabel(?string $shot, ?int $corner): ?string
    {
        return match (true) {
            $shot === 'extra' => 'Extra photo',
            $corner === null => null,
            $shot === 'nearest' => "Corner {$corner} (nearest spot)",
            default => "Corner {$corner}",
        };
    }

    /** @param array<string, mixed> $values */
    private function audit(Lease $lease, string $event, ?int $userId, array $values): void
    {
        AuditLog::query()->create([
            'organisation_id' => $lease->organisation_id,
            'actor_user_id' => $userId,
            'auditable_type' => $lease->getMorphClass(),
            'auditable_id' => $lease->getKey(),
            'event' => $event,
            'new_values' => $values,
        ]);
    }
}
