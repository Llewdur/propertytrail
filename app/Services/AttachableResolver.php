<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AreaRoom;
use App\Models\InspectionItem;
use App\Models\Lease;
use App\Models\Property;
use App\Repositories\AreaRoomRepository;
use App\Repositories\LeaseRepository;
use App\Repositories\PropertyRepository;

final class AttachableResolver
{
    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly AreaRoomRepository $rooms,
        private readonly LeaseRepository $leases,
        private readonly ItemPhotoGuard $itemGuard,
    ) {}

    /**
     * Returns a refusal message when the upload may not attach to this record, else null.
     *
     * @param class-string $type
     * @param array<string, mixed> $data
     */
    public function refusal(string $type, int $id, array $data): ?string
    {
        if ($type === InspectionItem::class) {
            return $this->itemGuard->refusal($id, $data['replaces_link_id'] ?? null);
        }

        if ($type !== Lease::class || !is_numeric($data['area_room_id'] ?? null)) {
            return null;
        }

        $lease = $this->leases->find($id);

        return $lease === null || !$this->rooms->coveredByLease($lease)->contains('id', (int) $data['area_room_id'])
            ? 'This lease does not cover that room or area.'
            : null;
    }

    /** @param class-string $type */
    public function exists(string $type, int $id): bool
    {
        return match ($type) {
            Property::class => $this->properties->find($id) !== null,
            AreaRoom::class => $this->rooms->find($id) !== null,
            Lease::class => $this->leases->find($id) !== null,
            InspectionItem::class => InspectionItem::query()->find($id) !== null,
            default => false,
        };
    }
}
