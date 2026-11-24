<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AreaRoom;
use Illuminate\Database\Eloquent\Collection;

final class AreaRoomRepository
{
    /** @return Collection<int, AreaRoom> */
    public function allForProperty(int $propertyId): Collection
    {
        return AreaRoom::query()->where('property_id', $propertyId)->orderBy('sort_order')->orderBy('id')->get();
    }

    public function find(int $id): ?AreaRoom
    {
        return AreaRoom::query()->find($id);
    }
}
