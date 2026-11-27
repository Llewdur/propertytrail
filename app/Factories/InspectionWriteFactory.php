<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\InspectionSection;
use App\Models\Lease;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\UsageRecord;
use App\Repositories\AreaRoomRepository;
use App\Services\Billing\EntitlementService;
use App\Services\InspectionTemplateService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class InspectionWriteFactory
{
    public function __construct(
        private readonly EntitlementService $billing,
        private readonly InspectionTemplateService $templates,
        private readonly AreaRoomRepository $areas,
    ) {}

    public function create(Property $property, string $phase, ?Lease $lease, ?string $overallNotes): Inspection
    {
        return DB::transaction(function () use ($property, $phase, $lease, $overallNotes): Inspection {
            $organisation = Organisation::query()->find($property->organisation_id);
            if (!$organisation instanceof Organisation) {
                throw new RuntimeException('The property has no organisation.');
            }

            $this->billing->assertCan('inspection', $organisation);

            $inspection = Inspection::query()->create([
                'organisation_id' => $property->organisation_id,
                'property_id' => $property->id,
                'lease_id' => $lease?->id,
                'phase' => $phase,
                'status' => Inspection::STATUS_DRAFT,
                'overall_notes' => $overallNotes,
            ]);

            $sectionSort = 0;

            $rooms = $lease === null
                ? $this->areas->allForProperty($property->id)
                : $this->areas->coveredByLease($lease);

            foreach ($rooms as $room) {
                $section = InspectionSection::query()->create([
                    'organisation_id' => $property->organisation_id,
                    'inspection_id' => $inspection->id,
                    'area_room_id' => $room->id,
                    'title' => $room->name,
                    'room_type' => $room->room_type,
                    'sort_order' => $sectionSort++,
                ]);

                $itemSort = 0;

                foreach ($this->templates->itemsForRoomType($organisation, $room->room_type) as $templateItem) {
                    InspectionItem::query()->create([
                        'organisation_id' => $property->organisation_id,
                        'inspection_section_id' => $section->id,
                        'label' => $templateItem->label,
                        'requires_note' => $templateItem->requires_note,
                        'source' => InspectionItem::SOURCE_DEFAULT,
                        'sort_order' => $itemSort++,
                    ]);
                }
            }

            $this->billing->recordUsage(UsageRecord::KIND_INSPECTION, $organisation, $inspection->id, 'inspection');

            return $inspection;
        });
    }
}
