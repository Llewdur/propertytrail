<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\Property;
use App\Support\Tenancy\CurrentOrganisation;

final class EvidenceWriteFactory
{
    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    /** @param array<string, mixed> $data */
    public function createRecord(array $data): EvidenceRecord
    {
        return EvidenceRecord::query()->create($data);
    }

    /**
     * @param class-string $attachableType
     * @param array<string, mixed> $context
     */
    public function link(
        EvidenceRecord $record,
        string $attachableType,
        int $attachableId,
        ?string $label = null,
        array $context = [],
    ): EvidenceLink {
        return EvidenceLink::query()->create([
            'organisation_id' => $this->currentOrganisation->id(),
            'evidence_record_id' => $record->getKey(),
            'attachable_type' => new $attachableType()->getMorphClass(),
            'attachable_id' => $attachableId,
            'label' => $label,
            'phase' => $context['phase'] ?? null,
            'area_room_id' => $context['area_room_id'] ?? null,
            'taken_at' => $context['taken_at'] ?? null,
            'replaces_link_id' => $context['replaces_link_id'] ?? null,
        ]);
    }

    public function linkToProperty(EvidenceRecord $record, Property $property, ?string $label = null): EvidenceLink
    {
        return $this->link($record, Property::class, $property->getKey(), $label);
    }
}
