<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Factories\InspectionWriteFactory;
use App\Factories\LeaseWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\InspectionSection;
use App\Services\ComparisonService;
use App\Services\ReportService;
use App\Support\Tenancy\CurrentOrganisation;
use Tests\Support\FakeStorageAdapter;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/** @param array<string, string> $conditions */
function comparisonInspection(object $test, string $phase, array $conditions): Inspection
{
    $inspection = app(InspectionWriteFactory::class)->create($test->property, $phase, $test->lease, null);
    $inspection->forceFill(['status' => Inspection::STATUS_SUBMITTED, 'submitted_at' => now()])->save();

    $section = InspectionSection::query()->create([
        'organisation_id' => $inspection->organisation_id,
        'inspection_id' => $inspection->id,
        'title' => 'Kitchen',
        'room_type' => 'kitchen',
        'sort_order' => 0,
    ]);

    foreach ($conditions as $label => $condition) {
        InspectionItem::query()->create([
            'organisation_id' => $inspection->organisation_id,
            'inspection_section_id' => $section->id,
            'label' => $label,
            'condition' => $condition,
            'source' => InspectionItem::SOURCE_MANUAL,
            'sort_order' => 0,
        ]);
    }

    return $inspection;
}

beforeEach(function (): void {
    resetTenant();
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());
    ['organisation' => $organisation] = tenantUser('compare');
    app(CurrentOrganisation::class)->set($organisation->id);
    $this->property = app(PropertyWriteFactory::class)->create(propertyPayload('Compare House'));
    $this->lease = app(LeaseWriteFactory::class)->create([
        'property_id' => $this->property->id,
        'lease_reference' => 'L-1',
        'tenant_name' => 'A. Tenant',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'active',
    ]);
});

it('categorises move-out items against the move-in baseline without assigning liability', function (): void {
    comparisonInspection($this, 'incoming', [
        'Counters' => 'fair',
        'Walls' => 'excellent',
        'Floor' => 'good',
        'Tiles' => 'good',
    ]);
    $outgoing = comparisonInspection($this, 'outgoing', [
        'Counters' => 'fair',
        'Walls' => 'good',
        'Floor' => 'damaged',
        'Tiles' => 'excellent',
        'Sink' => 'poor',
    ]);

    $result = app(ComparisonService::class)->compare($outgoing);
    $byItem = collect($result['rows'])->keyBy('item');

    expect($byItem['Counters']['category'])
        ->toBe(ComparisonService::EXISTING_DEFECT)
        ->and($byItem['Walls']['category'])
        ->toBe(ComparisonService::WEAR_AND_TEAR)
        ->and($byItem['Floor']['category'])
        ->toBe(ComparisonService::POTENTIAL_NEW_DAMAGE)
        ->and($byItem['Tiles']['category'])
        ->toBe(ComparisonService::IMPROVED)
        ->and($byItem['Sink']['category'])
        ->toBe(ComparisonService::NOT_AT_MOVE_IN)
        ->and(array_keys(ComparisonService::CATEGORY_LABELS))
        ->not
        ->toContain('liable')
        ->and($result['evidence_gaps'])
        ->toBe(2);
});

it('never reports unrated or missing items as unchanged', function (): void {
    $service = app(ComparisonService::class);
    comparisonInspection($this, 'incoming', ['Wardrobe' => 'good', 'Oven' => 'good']);
    $outgoing = comparisonInspection($this, 'outgoing', ['Oven' => null, 'Lamp' => 'good']);

    $byItem = collect($service->compare($outgoing)['rows'])->keyBy('item');

    expect($byItem['Oven']['category'])
        ->toBe(ComparisonService::NOT_ASSESSED)
        ->and($byItem['Lamp']['category'])
        ->toBe(ComparisonService::NOT_AT_MOVE_IN)
        ->and($byItem['Wardrobe']['category'])
        ->toBe(ComparisonService::NOT_AT_MOVE_OUT)
        ->and($service->categorise('average', 'fair', true))
        ->toBe(ComparisonService::NOT_AT_MOVE_IN);
});

it('has no comparison without a lease baseline', function (): void {
    $outgoing = comparisonInspection($this, 'outgoing', ['Walls' => 'good']);

    expect(app(ComparisonService::class)->compare($outgoing))->toBeNull();
});

it('renders the report with the comparison section for an outgoing inspection', function (): void {
    comparisonInspection($this, 'incoming', ['Counters' => 'fair']);
    $outgoing = comparisonInspection($this, 'outgoing', ['Counters' => 'fair']);

    $method = new ReflectionMethod(ReportService::class, 'render');
    $pdf = $method->invoke(app(ReportService::class), $outgoing->refresh(), 'PT-TEST');

    expect($pdf)->toStartWith('%PDF');
});
