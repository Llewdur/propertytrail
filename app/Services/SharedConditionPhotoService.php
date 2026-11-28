<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\AuditLog;
use App\Models\EvidenceLink;
use App\Models\Lease;
use App\Models\SharedConditionPhoto;
use App\Repositories\AreaRoomRepository;
use App\Support\MetadataFreeJpeg;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shares an earlier tenancy's condition photos with a later tenant, one approved photo at a time.
 * The new tenant gets a re-encoded copy (no location, device or capture time) and only the
 * fields on the allow-list in forTenant(); never the earlier tenant's name, notes, issues,
 * signatures or a way back to the original record.
 */
final class SharedConditionPhotoService
{
    public function __construct(
        private readonly StorageAdapterInterface $storage,
        private readonly AreaRoomRepository $areas,
    ) {}

    /**
     * A photo staff may share with this lease's tenant: one held on another tenancy of the same property.
     */
    public function shareable(Lease $lease, int $evidenceLinkId): ?EvidenceLink
    {
        /** @var EvidenceLink|null $photo */
        $photo = EvidenceLink::query()
            ->with('evidenceRecord')
            ->where('attachable_type', $lease->getMorphClass())
            ->where('attachable_id', '!=', $lease->id)
            ->whereIn('attachable_id', Lease::query()->where('property_id', $lease->property_id)->select('id'))
            ->find($evidenceLinkId);

        return $photo;
    }

    /**
     * Approves one photo for the lease's tenant. Staff may supply their own cropped or
     * blurred copy; either way the shared file is re-encoded so it carries no metadata.
     *
     * @throws RuntimeException when the image cannot be read
     */
    public function share(
        Lease $lease,
        EvidenceLink $source,
        ?UploadedFile $editedCopy,
        ?string $description,
        ?int $userId,
    ): SharedConditionPhoto {
        $bytes = $editedCopy === null ? $this->storage->get($source->evidenceRecord->storage_path) : $editedCopy->get();

        if (!is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The photo cannot be read.');
        }

        $copy = MetadataFreeJpeg::from($bytes);
        $path = sprintf(
            'organisations/%d/shared-condition/%d/%s.jpg',
            $lease->organisation_id,
            $lease->id,
            Str::random(40),
        );
        $this->storage->put($path, $copy);

        $covered =
            $source->area_room_id !== null
            && $this->areas->coveredByLease($lease)->contains('id', $source->area_room_id);

        return SharedConditionPhoto::query()->create([
            'organisation_id' => $lease->organisation_id,
            'lease_id' => $lease->id,
            'source_lease_id' => $source->attachable_id,
            'source_evidence_link_id' => $source->id,
            'area_room_id' => $covered ? $source->area_room_id : null,
            'storage_path' => $path,
            'mime_type' => 'image/jpeg',
            'sha256_hash' => hash('sha256', $copy),
            'edited' => $editedCopy !== null,
            'description' => $description,
            'approved_by_user_id' => $userId,
            'approved_at' => now(),
        ]);
    }

    public function withdraw(SharedConditionPhoto $photo): void
    {
        $photo->forceFill(['withdrawn_at' => now()])->save();
        $this->storage->delete($photo->storage_path);
    }

    /** @return Collection<int, SharedConditionPhoto> */
    public function forLease(Lease $lease): Collection
    {
        /** @var Collection<int, SharedConditionPhoto> $sorted */
        $sorted = SharedConditionPhoto::query()->where('lease_id', $lease->id)->get()->sortBy('id')->values();

        return $sorted;
    }

    public function find(int $leaseId, int $id): ?SharedConditionPhoto
    {
        return SharedConditionPhoto::query()->where('lease_id', $leaseId)->find($id);
    }

    /** A photo still shared with this lease's tenant. */
    public function findLive(int $leaseId, int $id): ?SharedConditionPhoto
    {
        /** @var SharedConditionPhoto|null $photo */
        $photo = SharedConditionPhoto::query()->where('lease_id', $leaseId)->whereNull('withdrawn_at')->find($id);

        return $photo;
    }

    /**
     * What the tenant sees: an allow-list, so a field added to the model never leaks.
     *
     * @return list<array{id: int, area_room_id: ?int, room_name: ?string, description: ?string, response: ?string, response_note: ?string}>
     */
    public function forTenant(int $leaseId): array
    {
        $rows = [];

        /** @var Collection<int, SharedConditionPhoto> $photos */
        $photos = SharedConditionPhoto::query()->with('areaRoom')->where('lease_id', $leaseId)->get()->sortBy('id');

        foreach ($photos as $photo) {
            if ($photo->withdrawn_at !== null) {
                continue;
            }

            $rows[] = [
                'id' => $photo->id,
                'area_room_id' => $photo->area_room_id,
                'room_name' => $photo->areaRoom?->name,
                'description' => $photo->description,
                'response' => $photo->tenant_response,
                'response_note' => $photo->tenant_response_note,
            ];
        }

        return $rows;
    }

    /** The new tenant confirms or disputes the condition a shared photo shows. */
    public function respond(SharedConditionPhoto $photo, string $response, ?string $note): void
    {
        $photo->forceFill([
            'tenant_response' => $response,
            'tenant_response_note' => $note,
            'responded_at' => now(),
        ])->save();

        AuditLog::query()->create([
            'organisation_id' => $photo->organisation_id,
            'actor_user_id' => null,
            'auditable_type' => $photo->getMorphClass(),
            'auditable_id' => $photo->id,
            'lease_id' => $photo->lease_id,
            'event' => 'shared_photo_' . $response,
            'new_values' => ['shared_photo_id' => $photo->id, 'response' => $response],
        ]);
    }

    public function contents(SharedConditionPhoto $photo): ?string
    {
        return $this->storage->get($photo->storage_path);
    }
}
