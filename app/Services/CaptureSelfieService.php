<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\AuditLog;
use App\Models\CaptureLink;
use App\Models\CaptureSelfie;
use App\Models\Lease;
use App\Support\CaptureTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** The tenant's selfie for one lease and phase: stored privately, apart from evidence. */
final class CaptureSelfieService
{
    public function __construct(
        private readonly StorageAdapterInterface $storage,
    ) {}

    public function taken(CaptureLink $link): bool
    {
        return $this->query($link->lease_id, $link->phase)->exists();
    }

    /**
     * Saves the selfie, or returns the one already held for the same bytes.
     * Null means the phase already holds the most selfies allowed.
     */
    public function record(CaptureLink $link, UploadedFile $file, mixed $takenAt): ?CaptureSelfie
    {
        $path = $file->getRealPath();
        $contents = $path === false ? false : file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Uploaded file cannot be read.');
        }

        $hash = hash('sha256', $contents);

        /** @var CaptureSelfie|null */
        return DB::transaction(function () use ($link, $file, $contents, $hash, $takenAt): ?CaptureSelfie {
            // Serialises a retried upload with the first one.
            Lease::query()->lockForUpdate()->find($link->lease_id);
            $held = $this->query($link->lease_id, $link->phase)->where('sha256_hash', $hash)->first();

            if ($held instanceof CaptureSelfie) {
                return $held;
            }

            if ($this->query($link->lease_id, $link->phase)->count() >= CaptureSelfie::MAX_PER_PHASE) {
                return null;
            }

            $extension = $file->getClientOriginalExtension();
            $extension = $extension === '' ? 'jpg' : $extension;
            $storagePath = sprintf(
                'private/organisations/%d/capture-selfies/%d/%s/%s.%s',
                $link->organisation_id,
                $link->lease_id,
                $link->phase,
                $hash,
                $extension,
            );
            $this->storage->put($storagePath, $contents);

            return CaptureSelfie::query()->create([
                'organisation_id' => $link->organisation_id,
                'lease_id' => $link->lease_id,
                'phase' => $link->phase,
                'capture_link_id' => $link->id,
                'storage_path' => $storagePath,
                'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                'file_size_bytes' => strlen($contents),
                'sha256_hash' => $hash,
                'taken_at' => CaptureTime::from($takenAt),
            ]);
        });
    }

    /** @return Collection<int, CaptureSelfie> */
    public function forLease(Lease $lease): Collection
    {
        /** @var Collection<int, CaptureSelfie> $selfies */
        $selfies = CaptureSelfie::query()
            ->where('lease_id', $lease->getKey())
            ->get()
            ->sortBy([['taken_at', 'asc'], ['id', 'asc']])
            ->values();

        return $selfies;
    }

    /**
     * The selfie's bytes for a member of staff, with the viewing audited.
     *
     * @return array{selfie: CaptureSelfie, contents: string}|null
     */
    public function open(Lease $lease, int $selfieId, ?int $userId): ?array
    {
        $selfie = CaptureSelfie::query()->where('lease_id', $lease->getKey())->find($selfieId);
        $contents = $selfie === null ? null : $this->storage->get($selfie->storage_path);

        if ($selfie === null || $contents === null) {
            return null;
        }

        AuditLog::query()->create([
            'organisation_id' => $selfie->organisation_id,
            'actor_user_id' => $userId,
            'auditable_type' => $selfie->getMorphClass(),
            'auditable_id' => $selfie->getKey(),
            'event' => 'capture_selfie_viewed',
            'new_values' => ['lease_id' => $selfie->lease_id, 'phase' => $selfie->phase],
        ]);

        return ['selfie' => $selfie, 'contents' => $contents];
    }

    /** @return Builder<CaptureSelfie> */
    private function query(int $leaseId, string $phase): Builder
    {
        return CaptureSelfie::query()->where('lease_id', $leaseId)->where('phase', $phase);
    }
}
