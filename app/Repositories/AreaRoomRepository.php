<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AreaRoom;
use App\Models\Lease;
use App\Models\Property;
use Illuminate\Database\Eloquent\Collection;

final class AreaRoomRepository
{
    /** @return Collection<int, AreaRoom> */
    public function allForProperty(int $propertyId): Collection
    {
        return AreaRoom::query()->where('property_id', $propertyId)->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * A unit's room only. Site areas are deliberately out of reach here: the
     * property access rules cannot guard a record that belongs to no unit.
     */
    public function find(int $id): ?AreaRoom
    {
        /** @var AreaRoom|null $room */
        $room = AreaRoom::query()->whereNotNull('property_id')->find($id);

        return $room;
    }

    public function findByCaptureKey(int $propertyId, string $captureKey): ?AreaRoom
    {
        return AreaRoom::query()->where('property_id', $propertyId)->where('capture_key', $captureKey)->first();
    }

    public function nextSortOrder(int $propertyId): int
    {
        return (int) AreaRoom::query()->where('property_id', $propertyId)->max('sort_order') + 1;
    }

    public function countCaptured(int $propertyId): int
    {
        return AreaRoom::query()->where('property_id', $propertyId)->whereNotNull('capture_key')->count();
    }

    public function findSiteArea(int $siteId, int $id): ?AreaRoom
    {
        return AreaRoom::query()->where('site_id', $siteId)->find($id);
    }

    /**
     * The unit's rooms, then its site's shared and outdoor areas.
     *
     * @return Collection<int, AreaRoom>
     */
    public function availableForLease(Lease $lease): Collection
    {
        $rooms = $this->allForProperty($lease->property_id);
        $siteId = Property::query()->find($lease->property_id)?->site_id;

        if ($siteId === null) {
            return $rooms;
        }

        /** @var Collection<int, AreaRoom> $available */
        $available = $rooms->concat(
            AreaRoom::query()->where('site_id', $siteId)->orderBy('sort_order')->orderBy('id')->get(),
        );

        return $available;
    }

    /**
     * The areas listed on the lease that are still available to it.
     *
     * @return Collection<int, AreaRoom>
     */
    public function listedForLease(Lease $lease): Collection
    {
        $listed = $lease->coveredAreas()->pluck('areas_rooms.id')->all();

        /** @var Collection<int, AreaRoom> $areas */
        $areas = $this->availableForLease($lease)->whereIn('id', $listed)->values();

        return $areas;
    }

    /**
     * What "the property" means for this tenant: the listed areas, or the unit's
     * rooms when none are listed.
     *
     * @return Collection<int, AreaRoom>
     */
    public function coveredByLease(Lease $lease): Collection
    {
        $listed = $this->listedForLease($lease);

        return $listed->isEmpty() ? $this->allForProperty($lease->property_id) : $listed;
    }
}
