<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AreaRoom;
use App\Models\EvidenceRecord;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\InspectionSection;
use App\Models\Lease;
use App\Models\Property;
use App\Repositories\AreaRoomRepository;
use App\Repositories\EvidenceRepository;
use App\Repositories\LeaseRepository;
use App\Repositories\PropertyRepository;

/** Which property a tenant record belongs to. */
final class PropertyResolver
{
    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly AreaRoomRepository $rooms,
        private readonly LeaseRepository $leases,
        private readonly EvidenceRepository $evidence,
    ) {}

    /**
     * The properties a route's record belongs to, or null when the route names
     * no record that exists in this organisation.
     *
     * @param array<string, mixed> $parameters
     * @return list<int>|null
     */
    public function propertyIdsForRoute(array $parameters): ?array
    {
        if (array_key_exists('evidence', $parameters)) {
            $record = $this->evidence->find((int) $parameters['evidence']);

            return $record === null ? null : $this->propertyIdsForEvidence($record);
        }

        $types = [
            'property' => Property::class,
            'room' => AreaRoom::class,
            'lease' => Lease::class,
            'inspection' => Inspection::class,
        ];

        $name = array_key_first(array_intersect_key($types, $parameters));
        $propertyId = $name === null ? null : $this->propertyId($types[$name], (int) $parameters[$name]);

        return $propertyId === null ? null : [$propertyId];
    }

    /** @param class-string $type */
    public function propertyId(string $type, int $id): ?int
    {
        $propertyId = match ($type) {
            Property::class => $this->properties->find($id)?->id,
            AreaRoom::class => $this->rooms->find($id)?->property_id,
            Lease::class => $this->leases->find($id)?->property_id,
            Inspection::class => Inspection::query()->find($id)?->property_id,
            InspectionItem::class => $this->propertyIdForItem($id),
            default => null,
        };

        return $propertyId === null ? null : (int) $propertyId;
    }

    /** @return list<int> */
    private function propertyIdsForEvidence(EvidenceRecord $record): array
    {
        $propertyIds = [];

        foreach ($record->links as $link) {
            $propertyId = $this->propertyId($link->attachable_type, (int) $link->attachable_id);

            if ($propertyId !== null) {
                $propertyIds[] = $propertyId;
            }
        }

        return $propertyIds;
    }

    private function propertyIdForItem(int $id): ?int
    {
        $sectionId = InspectionItem::query()->find($id)?->inspection_section_id;
        $inspectionId = $sectionId === null ? null : InspectionSection::query()->find($sectionId)?->inspection_id;

        return $inspectionId === null ? null : Inspection::query()->find($inspectionId)?->property_id;
    }
}
