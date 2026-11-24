<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganisationRole;
use App\Models\Property;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganisation;

final class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user
            ->organisations()
            ->whereKey(app(CurrentOrganisation::class)->id() ?? 0)
            ->exists();
    }

    public function view(User $user, Property $property): bool
    {
        if ($user->isAdminIn($property->organisation_id)) {
            return true;
        }

        return $property->assignments()->where('user_id', $user->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->isAdminIn(app(CurrentOrganisation::class)->id() ?? 0);
    }

    public function update(User $user, Property $property): bool
    {
        if ($user->isAdminIn($property->organisation_id)) {
            return true;
        }

        return $property
            ->assignments()
            ->where('user_id', $user->getKey())
            ->where('role', OrganisationRole::PropertyManager->value)
            ->exists();
    }

    public function delete(User $user, Property $property): bool
    {
        return $user->isAdminIn($property->organisation_id);
    }
}
