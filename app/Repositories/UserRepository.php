<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class UserRepository
{
    /** @return Collection<int, User> */
    public function all(): Collection
    {
        return User::query()->orderBy('name')->get();
    }

    public function find(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function belongsToOrganisation(User $user, int $organisationId): bool
    {
        return $user->organisations()->whereKey($organisationId)->exists();
    }
}
