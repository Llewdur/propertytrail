<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Property;
use Illuminate\Database\Eloquent\Collection;

final class PropertyRepository
{
    /** @return Collection<int, Property> */
    public function all(): Collection
    {
        return Property::query()->with('rooms')->orderByDesc('id')->get();
    }

    public function find(int $id): ?Property
    {
        return Property::query()->with('rooms')->find($id);
    }
}
