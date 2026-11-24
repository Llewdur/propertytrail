<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\Organisation;
use App\Models\Property;
use App\Models\UsageRecord;
use App\Services\Billing\EntitlementService;
use App\Support\Tenancy\CurrentOrganisation;

final class PropertyWriteFactory
{
    public function __construct(
        private readonly EntitlementService $billing,
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Property
    {
        $organisation = null;
        $organisationId = $data['organisation_id'] ?? $this->currentOrganisation->id();
        if (is_numeric($organisationId)) {
            $candidate = Organisation::query()->find((int) $organisationId);
            $organisation = $candidate instanceof Organisation ? $candidate : null;
        }

        if ($organisation !== null) {
            $this->billing->assertCan('property', $organisation);
        }

        $property = Property::query()->create($data);

        if ($organisation !== null) {
            $this->billing->recordUsage(UsageRecord::KIND_PROPERTY, $organisation, $property->id, 'property');
        }

        return $property;
    }

    /** @param array<string, mixed> $data */
    public function update(Property $property, array $data): Property
    {
        $property->fill($data)->save();

        return $property;
    }

    public function delete(Property $property): void
    {
        $property->delete();
    }
}
