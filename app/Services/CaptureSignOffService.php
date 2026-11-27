<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CaptureLink;
use App\Models\CapturePasskey;
use App\Models\CaptureSignOff;
use App\Models\Lease;
use App\Support\CaptureDeclarations;
use App\Support\SignatureImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The tenant's sign-off on their capture. A signature is bound to a hash of the frozen
 * rooms, ratings, notes and photo hashes; any later change leaves that version signed
 * for what it was and the capture unsigned until it is signed again.
 */
final class CaptureSignOffService
{
    public const STATUS_UNSIGNED = 'unsigned';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_CHANGED = 'changed';

    /** A phone unlock this recent counts as confirming the signer at the moment of signing. */
    private const UNLOCK_FRESH_SECONDS = 300;

    public function __construct(
        private readonly CaptureSignOffContent $contents,
        private readonly CaptureSelfieService $selfies,
    ) {}

    public function latest(int $leaseId, string $phase): ?CaptureSignOff
    {
        $version = (int) $this->signOffs($leaseId)->where('phase', $phase)->max('version');

        return $version === 0
            ? null
            : $this->signOffs($leaseId)->where('phase', $phase)->where('version', $version)->first();
    }

    /** Signed means the latest sign-off covers exactly what is held now. */
    public function status(?CaptureSignOff $latest, string $currentHash): string
    {
        return match (true) {
            $latest === null => self::STATUS_UNSIGNED,
            $latest->content_hash === $currentHash => self::STATUS_SIGNED,
            default => self::STATUS_CHANGED,
        };
    }

    /**
     * Records the sign-off for the content the tenant reviewed.
     *
     * @param array{signer_name: string, signature: string, content_hash: string, declaration_version: string, comment?: ?string} $data
     *
     * @throws InvalidArgumentException with the reason to show the tenant
     */
    public function sign(
        CaptureLink $link,
        array $data,
        UploadedFile $selfie,
        mixed $selfieTakenAt,
        ?string $ip,
        ?string $userAgent,
    ): CaptureSignOff {
        SignatureImage::assertValid($data['signature']);

        if ($data['declaration_version'] !== CaptureDeclarations::VERSION) {
            throw new InvalidArgumentException('The wording has changed. Review it again before you sign.');
        }

        /** @var CaptureSignOff */
        return DB::transaction(function () use (
            $link,
            $data,
            $selfie,
            $selfieTakenAt,
            $ip,
            $userAgent,
        ): CaptureSignOff {
            // Serialises two submissions, and freezes the content against an upload landing mid-sign.
            Lease::query()->lockForUpdate()->find($link->lease_id);

            $content = $this->contents->snapshot($link);
            $hash = $this->contents->hash($content);

            if (!hash_equals($hash, $data['content_hash'])) {
                throw new InvalidArgumentException(
                    'Something changed since you reviewed it. Review it again before you sign.',
                );
            }

            $latest = $this->latest($link->lease_id, $link->phase);

            if ($latest !== null && $latest->content_hash === $hash) {
                throw new InvalidArgumentException('You have already signed this record.');
            }

            $taken = $this->selfies->record($link, $selfie, $selfieTakenAt);

            if ($taken === null) {
                throw new InvalidArgumentException(
                    'The selfie limit for this capture has been reached. Ask the property\'s staff for help.',
                );
            }

            $comment = trim($data['comment'] ?? '');
            $signOff = CaptureSignOff::query()->create([
                'organisation_id' => $link->organisation_id,
                'lease_id' => $link->lease_id,
                'phase' => $link->phase,
                'version' => ($latest->version ?? 0) + 1,
                'capture_link_id' => $link->id,
                'signer_name' => trim($data['signer_name']),
                'signature_image' => $data['signature'],
                'declaration_version' => CaptureDeclarations::VERSION,
                'declarations' => CaptureDeclarations::for($content['tenant_name']),
                'content_hash' => $hash,
                'content' => $content,
                'comment' => $comment === '' ? null : $comment,
                'capture_selfie_id' => $taken->id,
                'phone_unlocked' => $this->freshlyUnlocked($link),
                'signer_ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'signed_at' => now(),
            ]);

            AuditLog::query()->create([
                'organisation_id' => $signOff->organisation_id,
                'actor_user_id' => null,
                'auditable_type' => $signOff->getMorphClass(),
                'auditable_id' => $signOff->getKey(),
                'event' => 'capture_signed_off',
                'new_values' => [
                    'lease_id' => $signOff->lease_id,
                    'phase' => $signOff->phase,
                    'version' => $signOff->version,
                    'content_hash' => $hash,
                    'declaration_version' => $signOff->declaration_version,
                    'phone_unlocked' => $signOff->phone_unlocked,
                    'commented' => $signOff->comment !== null,
                ],
            ]);

            return $signOff;
        });
    }

    /** @return Collection<int, CaptureSignOff> */
    public function forLease(Lease $lease): Collection
    {
        /** @var Collection<int, CaptureSignOff> $signOffs */
        $signOffs = $this
            ->signOffs((int) $lease->getKey())
            ->get()
            ->sortBy([['phase', 'asc'], ['version', 'asc']])
            ->values();

        return $signOffs;
    }

    /** @return Builder<CaptureSignOff> */
    private function signOffs(int $leaseId): Builder
    {
        return CaptureSignOff::query()->where('lease_id', $leaseId);
    }

    private function freshlyUnlocked(CaptureLink $link): bool
    {
        $passkey = CapturePasskey::query()->where('lease_id', $link->lease_id)->where('phase', $link->phase)->first();
        $usedAt = $passkey?->last_used_at;

        return $usedAt !== null && $usedAt->gte(now()->subSeconds(self::UNLOCK_FRESH_SECONDS));
    }
}
