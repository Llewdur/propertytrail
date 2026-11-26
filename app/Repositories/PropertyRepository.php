<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Property;
use App\Models\PropertyAssignment;
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
        $propertyIds = $this->visibleIdsFor($user);

        return $propertyIds === null ? $this->all() : $this->all()->whereIn('id', $propertyIds)->values();
    }

    /**
     * The properties this user is limited to, or null for an organisation admin.
     *
     * @return list<int>|null
     */
    public function visibleIdsFor(User $user): ?array
    {
        if ($user->isAdminIn(app(CurrentOrganisation::class)->id() ?? 0)) {
            return null;
        }

        return array_values(
            PropertyAssignment::query()
                ->where('user_id', $user->getKey())
                ->pluck('property_id')
                ->map(static fn(mixed $id): int => (int) $id)
                ->all(),
        );
    }

    public function find(int $id): ?Property
    {
        return Property::query()->with('rooms')->find($id);
    }
}
