<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Property;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Database\Eloquent\Collection;

final class PropertyRepository
{
    /** @return Collection<int, Property> */
    public function all(): Collection
    {
        return Property::query()->with('rooms')->orderByDesc('id')->get();
    }

    /** @return Collection<int, Property> */
    public function allForUser(User $user): Collection
    {
        $properties = $this->all();

        if ($user->isAdminIn(app(CurrentOrganisation::class)->id() ?? 0)) {
            return $properties;
        }

        /** @var array<int, Property> $assigned */
        $assigned = $properties
            ->filter(
                static fn(Property $property): bool => $property->assignedUsers()->whereKey($user->getKey())->exists(),
            )
            ->values()
            ->all();

        /** @var Collection<int, Property> $result */
        $result = new Collection($assigned);

        return $result->values();
    }

    public function find(int $id): ?Property
    {
        return Property::query()->with('rooms')->find($id);
    }
}
