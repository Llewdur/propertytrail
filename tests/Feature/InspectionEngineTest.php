<?php

declare(strict_types=1);

use App\Factories\AreaRoomWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\Property;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganisation;

use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function asOrgUser(User $user, int $organisationId): mixed
{
    return withHeader('X-Organisation-Id', (string) $organisationId)->actingAs($user);
}

function addRoom(Property $property, string $name, string $type): \App\Models\AreaRoom
{
    return app(AreaRoomWriteFactory::class)->create([
        'organisation_id' => $property->organisation_id,
        'property_id' => $property->id,
        'name' => $name,
        'room_type' => $type,
    ]);
}

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $organisation, 'user' => $user] = tenantUser('alpha');
    $this->organisation = $organisation;
    $this->user = $user;

    app(CurrentOrganisation::class)->set($organisation->id);
    $this->property = app(PropertyWriteFactory::class)->create(propertyPayload('Main House'));
    resetTenant();
});

it('seeds rooms and default items when an inspection is created', function (): void {
    addRoom($this->property, 'Kitchen', 'kitchen');
    addRoom($this->property, 'Lounge', 'living');

    asOrgUser($this->user, $this->organisation->id)
        ->postJson('/api/v1/inspections', ['property_id' => $this->property->id, 'phase' => 'incoming'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.progress.sections_total', 2)
        ->assertJsonPath('data.progress.items_total', 12)
        ->assertJsonPath('data.sections.0.items.0.label', 'Walls')
        ->assertJsonPath('data.sections.0.items.2.label', 'Counters')
        ->assertJsonPath('data.sections.1.items.0.label', 'Walls');
});

it('a room save persists ratings, notes and progress', function (): void {
    addRoom($this->property, 'Kitchen', 'kitchen');

    $created = asOrgUser($this->user, $this->organisation->id)
        ->postJson('/api/v1/inspections', ['property_id' => $this->property->id, 'phase' => 'incoming'])
        ->assertCreated();

    $inspectionId = (int) $created->json('data.id');
    $sectionId = (int) $created->json('data.sections.0.id');

    $payload = [];
    foreach ($created->json('data.sections.0.items') as $index => $item) {
        $payload[] = [
            'id' => $item['id'],
            'label' => $item['label'],
            'condition' => $index < 4 ? 'excellent' : null,
            'note' => $index === 0 ? 'approx. 20 cm scuff behind door' : null,
        ];
    }

    asOrgUser($this->user, $this->organisation->id)
        ->putJson("/api/v1/inspections/{$inspectionId}/sections/{$sectionId}", ['items' => $payload])
        ->assertOk()
        ->assertJsonPath('data.progress.sections_saved', 1)
        ->assertJsonPath('data.progress.items_rated', 4)
        ->assertJsonPath('data.sections.0.items.0.note', 'approx. 20 cm scuff behind door');

    expect(\App\Models\InspectionItem::query()->withoutGlobalScopes()->where('condition', 'excellent')->count())
        ->toBe(4);
    expect(\App\Models\InspectionSection::query()->withoutGlobalScopes()->find($sectionId)->saved_at)->not->toBeNull();
});

it('submit refuses until rooms are saved and items are rated', function (): void {
    addRoom($this->property, 'Kitchen', 'kitchen');
    addRoom($this->property, 'Lounge', 'living');

    $client = asOrgUser($this->user, $this->organisation->id);
    $inspectionId = (int) $client
        ->postJson('/api/v1/inspections', ['property_id' => $this->property->id, 'phase' => 'incoming'])
        ->assertCreated()
        ->json('data.id');

    $client
        ->postJson("/api/v1/inspections/{$inspectionId}/submit")
        ->assertStatus(422)
        ->assertJsonPath('errors.0', 'Save Kitchen before submitting.')
        ->assertJsonPath('errors.1', 'Save Lounge before submitting.')
        ->assertJsonPath('errors.2', '12 item(s) are not rated yet.');

    $sections = $client->getJson("/api/v1/inspections/{$inspectionId}")->assertOk()->json('data.sections');

    foreach ($sections as $section) {
        $rows = array_map(static fn(array $item): array => [
            'id' => $item['id'],
            'label' => $item['label'],
            'condition' => 'good',
            'note' => null,
        ], $section['items']);

        $client->putJson("/api/v1/inspections/{$inspectionId}/sections/{$section['id']}", [
            'items' => $rows,
        ])->assertOk();
    }

    $client
        ->postJson("/api/v1/inspections/{$inspectionId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'submitted');

    expect(Inspection::query()->withoutGlobalScopes()->find($inspectionId)->submitted_at)->not->toBeNull();
});

it('a submitted inspection is locked for edits and audited', function (): void {
    addRoom($this->property, 'Kitchen', 'kitchen');

    $client = asOrgUser($this->user, $this->organisation->id);
    $created = $client
        ->postJson('/api/v1/inspections', ['property_id' => $this->property->id, 'phase' => 'outgoing'])
        ->assertCreated();

    $inspectionId = (int) $created->json('data.id');
    $section = $created->json('data.sections.0');

    $rows = array_map(static fn(array $item): array => [
        'id' => $item['id'],
        'label' => $item['label'],
        'condition' => 'fair',
        'note' => null,
    ], $section['items']);

    $client->putJson("/api/v1/inspections/{$inspectionId}/sections/{$section['id']}", ['items' => $rows])->assertOk();
    $client->postJson("/api/v1/inspections/{$inspectionId}/submit")->assertOk();

    $client->putJson("/api/v1/inspections/{$inspectionId}/sections/{$section['id']}", [
        'items' => $rows,
    ])->assertStatus(422)->assertJsonPath('message', 'A submitted inspection can no longer be changed. Start a new inspection instead.');

    $client
        ->postJson("/api/v1/inspections/{$inspectionId}/submit")
        ->assertStatus(422)
        ->assertJsonPath('errors.0', 'This inspection has already been submitted.');

    $morph = new Inspection()->getMorphClass();
    expect(
        AuditLog::query()
            ->withoutGlobalScopes()
            ->where('auditable_type', $morph)
            ->where('auditable_id', $inspectionId)
            ->count(),
    )
        ->toBeGreaterThanOrEqual(2);

    $client->getJson('/api/v1/audit-logs')->assertOk();
});

it('another organisation cannot read or change an inspection', function (): void {
    addRoom($this->property, 'Kitchen', 'kitchen');

    $inspectionId = (int) asOrgUser($this->user, $this->organisation->id)
        ->postJson('/api/v1/inspections', ['property_id' => $this->property->id, 'phase' => 'incoming'])
        ->assertCreated()
        ->json('data.id');

    ['organisation' => $beta, 'user' => $betaUser] = tenantUser('beta');
    app(CurrentOrganisation::class)->set($beta->id);
    app(PropertyWriteFactory::class)->create(propertyPayload('Beta House'));
    resetTenant();

    $client = asOrgUser($betaUser, $beta->id);

    $client->getJson('/api/v1/inspections')->assertOk()->assertJsonCount(0, 'data');
    $client->getJson("/api/v1/inspections/{$inspectionId}")->assertNotFound();
    $client->putJson("/api/v1/inspections/{$inspectionId}/sections/1", [
        'items' => [['label' => 'Walls', 'condition' => 'good']],
    ])->assertNotFound();
    $client->postJson("/api/v1/inspections/{$inspectionId}/submit")->assertNotFound();
    $client
        ->postJson('/api/v1/inspections', ['property_id' => $this->property->id, 'phase' => 'incoming'])
        ->assertNotFound();
});
