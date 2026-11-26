<?php

declare(strict_types=1);

use App\Factories\AreaRoomWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Factories\UserWriteFactory;
use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use App\Models\User;
use App\Services\InspectionTemplateService;
use App\Support\InspectionItemDefaults;
use App\Support\Tenancy\CurrentOrganisation;
use Database\Seeders\InspectionTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\withHeader;

uses(RefreshDatabase::class);

function asTemplateOrgUser(User $user, int $organisationId): mixed
{
    return withHeader('X-Organisation-Id', (string) $organisationId)->actingAs($user);
}

/** @return array<string, mixed> */
function templateKitchenRoom(int $propertyId, int $organisationId): array
{
    return [
        'organisation_id' => $organisationId,
        'property_id' => $propertyId,
        'name' => 'Kitchen',
        'room_type' => 'kitchen',
    ];
}

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $organisation, 'user' => $user] = tenantUser('templates');
    $this->organisation = $organisation;
    $this->user = $user;
    app(CurrentOrganisation::class)->set($organisation->id);
});

it('seeds a default template per room type without duplicating or overwriting edits', function (): void {
    $this->seed(InspectionTemplateSeeder::class);

    $templates = InspectionTemplate::query()->forOrganisation($this->organisation->id)->get();
    expect($templates)->toHaveCount(count(InspectionItemDefaults::roomTypes()));

    $kitchen = $templates->firstWhere('room_type', 'kitchen');
    expect($kitchen->items->pluck('label')->all())->toBe(InspectionItemDefaults::forRoomType('kitchen'));

    app(InspectionTemplateService::class)->saveItems($kitchen, [['label' => 'Worktop']]);
    $this->seed(InspectionTemplateSeeder::class);

    expect(InspectionTemplate::query()->forOrganisation($this->organisation->id)->count())
        ->toBe(count(InspectionItemDefaults::roomTypes()));
    expect($kitchen->refresh()->items->pluck('label')->all())->toBe(['Worktop']);
});

it('copies the current organisation template into new inspection items', function (): void {
    $this->seed(InspectionTemplateSeeder::class);

    $property = app(PropertyWriteFactory::class)->create(propertyPayload('Template House'));
    app(AreaRoomWriteFactory::class)->create(templateKitchenRoom($property->id, $this->organisation->id));

    $kitchen = InspectionTemplate::query()
        ->forOrganisation($this->organisation->id)
        ->where('room_type', 'kitchen')
        ->firstOrFail();
    app(InspectionTemplateService::class)->saveItems($kitchen, [
        ['label' => 'Worktop'],
        ['label' => 'Extractor fan', 'requires_note' => true],
    ]);

    resetTenant();
    $created = asTemplateOrgUser($this->user, $this->organisation->id)
        ->postJson('/api/v1/inspections', ['property_id' => $property->id, 'phase' => 'incoming'])
        ->assertCreated();

    expect($created->json('data.progress.items_total'))->toBe(2);
    expect($created->json('data.sections.0.items.*.label'))->toBe(['Worktop', 'Extractor fan']);

    $catalogue = asTemplateOrgUser($this->user, $this->organisation->id)
        ->getJson('/api/v1/inspection-templates')
        ->assertOk();

    expect($catalogue->json('condition_scale.*.key'))->toBe(['excellent', 'good', 'fair', 'poor', 'damaged']);
});

it('keeps recorded and locked inspection items when the template changes', function (): void {
    $this->seed(InspectionTemplateSeeder::class);

    $settings = $this->organisation->settings ?? [];
    $settings['plan'] = 'starter';
    $this->organisation->settings = $settings;
    $this->organisation->save();

    $property = app(PropertyWriteFactory::class)->create(propertyPayload('Locked House'));
    app(AreaRoomWriteFactory::class)->create(templateKitchenRoom($property->id, $this->organisation->id));

    $client = asTemplateOrgUser($this->user, $this->organisation->id);
    $created = $client
        ->postJson('/api/v1/inspections', ['property_id' => $property->id, 'phase' => 'outgoing'])
        ->assertCreated()
        ->json('data');

    $inspectionId = (int) $created['id'];
    $sectionId = (int) $created['sections'][0]['id'];
    $oldLabels = array_column($created['sections'][0]['items'], 'label');
    $rows = array_map(static fn(array $item): array => [
        'id' => $item['id'],
        'label' => $item['label'],
        'condition' => 'good',
        'note' => null,
    ], $created['sections'][0]['items']);

    $client->putJson("/api/v1/inspections/{$inspectionId}/sections/{$sectionId}", ['items' => $rows])->assertOk();
    $client->postJson("/api/v1/inspections/{$inspectionId}/submit")->assertOk();

    $kitchen = InspectionTemplate::query()
        ->forOrganisation($this->organisation->id)
        ->where('room_type', 'kitchen')
        ->firstOrFail();
    $client
        ->putJson("/api/v1/inspection-templates/{$kitchen->id}", [
            'items' => [['label' => 'Brand new counter', 'requires_note' => true]],
        ])
        ->assertOk()
        ->assertJsonPath('data.items.0.label', 'Brand new counter');

    $section = Inspection::query()->findOrFail($inspectionId)->sections()->with('items')->firstOrFail();
    expect($section->items->pluck('label')->all())->toBe($oldLabels);
    expect($section->items->first()->condition)->toBe('good');

    $client
        ->postJson('/api/v1/inspections', ['property_id' => $property->id, 'phase' => 'incoming'])
        ->assertCreated()
        ->assertJsonPath('data.sections.0.items.0.label', 'Brand new counter');
});

it('allows only organisation admins to edit templates and hides other tenants templates', function (): void {
    $this->seed(InspectionTemplateSeeder::class);
    $kitchen = InspectionTemplate::query()
        ->forOrganisation($this->organisation->id)
        ->where('room_type', 'kitchen')
        ->firstOrFail();

    ['organisation' => $beta, 'user' => $betaUser] = tenantUser('beta');
    app(CurrentOrganisation::class)->set($beta->id);
    $this->seed(InspectionTemplateSeeder::class);
    resetTenant();

    asTemplateOrgUser($betaUser, $beta->id)->getJson("/api/v1/inspection-templates/{$kitchen->id}")->assertNotFound();
    asTemplateOrgUser($betaUser, $beta->id)->putJson("/api/v1/inspection-templates/{$kitchen->id}", [
        'items' => [['label' => 'Stolen']],
    ])->assertNotFound();

    app(CurrentOrganisation::class)->set($this->organisation->id);
    $member = app(UserWriteFactory::class)->create([
        'name' => 'Member',
        'email' => 'member-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);
    app(UserWriteFactory::class)->attachToOrganisation($member, $this->organisation, 'member');
    resetTenant();

    asTemplateOrgUser($member, $this->organisation->id)->putJson("/api/v1/inspection-templates/{$kitchen->id}", [
        'items' => [['label' => 'Not allowed']],
    ])->assertForbidden();
});

it('does not repopulate a deliberately emptied template', function (): void {
    $this->seed(InspectionTemplateSeeder::class);
    $kitchen = InspectionTemplate::query()
        ->forOrganisation($this->organisation->id)
        ->where('room_type', 'kitchen')
        ->firstOrFail();

    app(InspectionTemplateService::class)->saveItems($kitchen, []);
    app(InspectionTemplateService::class)->ensureDefaultsFor($this->organisation);

    expect($kitchen->refresh()->items()->count())->toBe(0);
});

it('refuses to submit while an item that requires a note has none', function (): void {
    $this->seed(InspectionTemplateSeeder::class);
    $property = app(PropertyWriteFactory::class)->create(propertyPayload('Note House'));
    app(AreaRoomWriteFactory::class)->create(templateKitchenRoom($property->id, $this->organisation->id));
    $kitchen = InspectionTemplate::query()
        ->forOrganisation($this->organisation->id)
        ->where('room_type', 'kitchen')
        ->firstOrFail();
    app(InspectionTemplateService::class)->saveItems($kitchen, [['label' => 'Extractor fan', 'requires_note' => true]]);

    $client = asTemplateOrgUser($this->user, $this->organisation->id);
    $created = $client
        ->postJson('/api/v1/inspections', ['property_id' => $property->id, 'phase' => 'incoming'])
        ->assertCreated()
        ->json('data');
    $section = "/api/v1/inspections/{$created['id']}/sections/{$created['sections'][0]['id']}";
    $row = ['id' => $created['sections'][0]['items'][0]['id'], 'label' => 'Extractor fan', 'condition' => 'good'];

    $client->putJson($section, ['items' => [$row]])->assertOk();
    $client
        ->postJson("/api/v1/inspections/{$created['id']}/submit")
        ->assertUnprocessable()
        ->assertJsonPath('errors.0', 'Kitchen: Extractor fan needs a note.');

    $client->putJson($section, ['items' => [$row + ['note' => 'Runs quietly']]])->assertOk();
    $client->postJson("/api/v1/inspections/{$created['id']}/submit")->assertOk();
});

it('audits every removed template item', function (): void {
    $this->seed(InspectionTemplateSeeder::class);
    $kitchen = InspectionTemplate::query()
        ->forOrganisation($this->organisation->id)
        ->where('room_type', 'kitchen')
        ->firstOrFail();
    $removed = $kitchen->items->pluck('id')->all();

    app(InspectionTemplateService::class)->saveItems($kitchen, [['label' => 'Worktop']]);

    expect(
        AuditLog::query()
            ->where('auditable_type', InspectionTemplateItem::class)
            ->where('event', 'deleted')
            ->whereIn('auditable_id', $removed)
            ->count(),
    )
        ->toBe(count($removed));
});
