<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\Lease;
use App\Support\Tenancy\CurrentOrganisation;

final class LeaseWriteFactory
{
    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Lease
    {
        $data['organisation_id'] ??= $this->currentOrganisation->id();

        return Lease::query()->create($data);
    }

    /** @param array<int, int> $areaIds */
    public function setCoveredAreas(Lease $lease, array $areaIds): void
    {
        $lease->coveredAreas()->syncWithPivotValues($areaIds, ['organisation_id' => $lease->organisation_id]);
    }

    public function addCoveredArea(Lease $lease, int $areaId): void
    {
        $lease->coveredAreas()->syncWithoutDetaching([$areaId => ['organisation_id' => $lease->organisation_id]]);
    }
}
