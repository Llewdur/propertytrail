<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Property;
use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

final class SiteRepository
{
    /**
     * @param list<int>|null $visiblePropertyIds null means every site
     * @return Collection<int, Site>
     */
    public function all(?array $visiblePropertyIds): Collection
    {
        $units = Property::query()->select(['id', 'site_id', 'name'])->orderBy('id');

        if ($visiblePropertyIds !== null) {
            $units->whereIn('id', $visiblePropertyIds);
        }

        $unitsBySite = $units->get()->groupBy('site_id');

        $query = Site::query()->with('areas');
        $query->orderBy('name')->orderBy('id');
        $sites = $query->get();

        foreach ($sites as $site) {
            $site->setRelation('units', $unitsBySite->get($site->id, new Collection()));
        }

        if ($visiblePropertyIds === null) {
            return $sites;
        }

        /** @var Collection<int, Site> $visible */
        $visible = $sites->filter(static fn(Site $site): bool => $unitsBySite->has($site->id))->values();

        return $visible;
    }

    public function find(int $id): ?Site
    {
        return Site::query()->find($id);
    }
}
