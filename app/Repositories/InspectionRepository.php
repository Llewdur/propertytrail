<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Inspection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class InspectionRepository
{
    /**
     * @param list<int>|null $propertyIds limits the result to these properties; null means no limit
     * @return Collection<int, Inspection>
     */
    public function all(?array $propertyIds = null): Collection
    {
        return Inspection::query()
            ->with(['sections.items', 'property:id,name', 'lease:id,lease_reference', 'signatures'])
            ->when($propertyIds !== null, static fn(Builder $query) => $query->whereIn('property_id', $propertyIds))
            ->orderByDesc('id')
            ->get();
    }

    public function find(int $id): ?Inspection
    {
        return Inspection::query()
            ->with(['sections.items', 'property:id,name', 'lease:id,lease_reference', 'signatures'])
            ->find($id);
    }
}
