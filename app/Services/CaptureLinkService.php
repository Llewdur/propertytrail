<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CaptureLink;
use App\Models\EvidenceLink;
use App\Models\Lease;
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
    ) {}

    /**
     * Issues the lease's one live link, revoking any earlier one. The plain
     * token is returned once and never stored.
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
                'expires_at' => now()->addDays(self::LINK_TTL_DAYS),
                'created_by_user_id' => $userId,
            ]);
            $this->audit($lease, 'capture_link_issued', $userId, ['capture_link_id' => $link->id, 'phase' => $phase]);

            // In the fragment, which browsers never send: the token stays out of server, proxy and referrer logs.
            return ['link' => $link, 'url' => url('/capture') . '#' . $plain];
        });
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
            $link->forceFill(['last_used_at' => now()])->save();
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

    /** A retried upload of the same bytes to the same area returns the photo already held. */
    public function addPhoto(CaptureLink $link, UploadedFile $file, int $areaId): EvidenceLink
    {
        return DB::transaction(function () use ($link, $file, $areaId): EvidenceLink {
            CaptureLink::query()->lockForUpdate()->find($link->id);

            $hash = hash('sha256', (string) $file->get());
            $held = $this
                ->photos($link)
                ->where('area_room_id', $areaId)
                ->whereHas('evidenceRecord', static fn(Builder $record) => $record->where('sha256_hash', $hash))
                ->first();

            if ($held instanceof EvidenceLink) {
                return $held;
            }

            $record = $this->ingestion->ingest($file, Lease::class, $link->lease_id, null, [
                'phase' => $link->phase,
                'area_room_id' => $areaId,
                'taken_at' => now(),
            ]);

            /** @var EvidenceLink $photo */
            $photo = $record->links()->latest('id')->firstOrFail();
            $photo->forceFill(['capture_link_id' => $link->id])->save();

            return $photo;
        });
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
