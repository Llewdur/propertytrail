<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Lease;
use Illuminate\Support\Collection;

final class LeaseRepository
{
    public function forProperty(int $propertyId): Collection
    {
        return Lease::query()->where('property_id', $propertyId)->orderByDesc('start_date')->orderBy('id')->get();
    }

    public function find(int $id): ?Lease
    {
        return Lease::query()->find($id);
    }
}
