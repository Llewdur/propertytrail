<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Collection;

final class OrganisationRepository
{
    public function find(int $id): ?Organisation
    {
        return Organisation::query()->find($id);
    }

    public function findBySlug(string $slug): ?Organisation
    {
        return Organisation::query()->where('slug', $slug)->first();
    }

    /** @return Collection<int, Organisation> */
    public function allForUser(int $userId): Collection
    {
        return Organisation::query()
            ->join('organisation_user', 'organisations.id', '=', 'organisation_user.organisation_id')
            ->where('organisation_user.user_id', $userId)
            ->select('organisations.*')
            ->get();
    }
}
