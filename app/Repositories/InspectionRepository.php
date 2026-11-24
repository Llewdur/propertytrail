<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Inspection;
use Illuminate\Database\Eloquent\Collection;

final class InspectionRepository
{
    /** @return Collection<int, Inspection> */
    public function all(): Collection
    {
        return Inspection::query()
            ->with(['sections.items', 'property:id,name', 'lease:id,lease_reference', 'signatures'])
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
