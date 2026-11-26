<?php

declare(strict_types=1);

use App\Factories\AreaRoomWriteFactory;
use App\Factories\InspectionWriteFactory;
use App\Factories\LeaseWriteFactory;
use App\Factories\OrganisationWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Factories\UserWriteFactory;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganisation;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('assign');

    $this->users = app(UserWriteFactory::class);

    $this->manager = $this->users->create([
        'name' => 'Assigned Manager',
        'email' => 'assigned-manager-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);
    $this->users->attachToOrganisation($this->manager, $this->organisation, 'property_manager');

    $this->viewer = $this->users->create([
        'name' => 'Viewer',
        'email' => 'viewer-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);
    $this->users->attachToOrganisation($this->viewer, $this->organisation, 'viewer');

    app(CurrentOrganisation::class)->set($this->organisation->id);
    $this->property = app(PropertyWriteFactory::class)->create(propertyPayload('Assigned House'));
    resetTenant();
});

it('assigns a manager who can then see only that property', function (): void {
    $admin = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $admin()->postJson("/api/v1/properties/{$this->property->id}/assignments", [
        'user_id' => $this->manager->id,
        'role' => 'property_manager',
    ])->assertCreated();

    $this->assertDatabaseHas('property_user', [
        'organisation_id' => $this->organisation->id,
        'property_id' => $this->property->id,
        'user_id' => $this->manager->id,
        'role' => 'property_manager',
    ]);

    actingAs($this->manager)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->getJson('/api/v1/properties')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->property->id);

    actingAs($this->viewer)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->getJson('/api/v1/properties')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    actingAs($this->viewer)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->getJson("/api/v1/properties/{$this->property->id}")
        ->assertForbidden();
});

it('removes an assignment', function (): void {
    actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/properties/{$this->property->id}/assignments", [
            'user_id' => $this->manager->id,
            'role' => 'property_manager',
        ])
        ->assertCreated();

    actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->deleteJson("/api/v1/properties/{$this->property->id}/assignments/{$this->manager->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('property_user', [
        'property_id' => $this->property->id,
        'user_id' => $this->manager->id,
    ]);
});

it('cannot assign a property from another tenant', function (): void {
    $beta = app(OrganisationWriteFactory::class)->create(['name' => 'Organisation Beta']);

    app(CurrentOrganisation::class)->set($beta->id);
    $betaProperty = app(PropertyWriteFactory::class)->create(propertyPayload('Beta House'));
    resetTenant();

    actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/properties/{$betaProperty->id}/assignments", [
            'user_id' => $this->manager->id,
            'role' => 'property_manager',
        ])
        ->assertNotFound();
});

it('refuses assignments to users outside the organisation', function (): void {
    $outsider = $this->users->create([
        'name' => 'Outsider',
        'email' => 'outsider-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);

    actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/properties/{$this->property->id}/assignments", [
            'user_id' => $outsider->id,
            'role' => 'property_manager',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('user_id');
});

it('applies the assignment to every route under a property', function (): void {
    app(CurrentOrganisation::class)->set($this->organisation->id);
    $room = app(AreaRoomWriteFactory::class)->create([
        'property_id' => $this->property->id,
        'name' => 'Kitchen',
        'room_type' => 'kitchen',
    ]);
    $lease = app(LeaseWriteFactory::class)->create([
        'property_id' => $this->property->id,
        'lease_reference' => 'L-ACCESS',
        'tenant_name' => 'A. Tenant',
        'start_date' => '2026-01-01',
        'status' => 'active',
    ]);
    $inspection = app(InspectionWriteFactory::class)->create($this->property, 'incoming', $lease, null);
    $record = EvidenceRecord::query()->create([
        'organisation_id' => $this->organisation->id,
        'sha256_hash' => hash('sha256', 'access'),
        'file_name' => 'a.jpg',
        'mime_type' => 'image/jpeg',
        'file_size_bytes' => 1,
        'storage_driver' => 'r2',
        'storage_path' => 'evidence/a.jpg',
    ]);
    EvidenceLink::query()->create([
        'organisation_id' => $this->organisation->id,
        'evidence_record_id' => $record->id,
        'attachable_type' => $lease->getMorphClass(),
        'attachable_id' => $lease->id,
    ]);
    resetTenant();

    $as = fn(User $user) => actingAs($user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);
    $property = "/api/v1/properties/{$this->property->id}";
    $reads = [
        "{$property}/rooms",
        "{$property}/leases",
        "{$property}/lease-photos",
        "/api/v1/leases/{$lease->id}",
        "/api/v1/inspections/{$inspection->id}",
        "/api/v1/inspections/{$inspection->id}/deliveries",
        "/api/v1/inspections/{$inspection->id}/report",
        "/api/v1/inspections/{$inspection->id}/report/download",
        "/api/v1/evidence/{$record->id}",
        "/api/v1/evidence/{$record->id}/download",
    ];

    foreach ($reads as $url) {
        $as($this->viewer)->getJson($url)->assertForbidden();
    }

    $as($this->viewer)->getJson('/api/v1/inspections')->assertOk()->assertJsonCount(0, 'data');
    $as($this->viewer)->getJson('/api/v1/evidence')->assertOk()->assertJsonCount(0, 'data');
    $as($this->viewer)
        ->postJson('/api/v1/inspections', [
            'property_id' => $this->property->id,
            'phase' => 'incoming',
        ])
        ->assertForbidden();
    $as($this->viewer)->postJson("/api/v1/inspections/{$inspection->id}/submit")->assertForbidden();
    $as($this->viewer)->postJson("{$property}/rooms", ['name' => 'Den', 'room_type' => 'lounge'])->assertForbidden();
    $as($this->viewer)->deleteJson("/api/v1/rooms/{$room->id}")->assertForbidden();

    $as($this->user)->postJson("{$property}/assignments", [
        'user_id' => $this->viewer->id,
        'role' => 'viewer',
    ])->assertCreated();

    $as($this->viewer)->getJson("{$property}/rooms")->assertOk();
    $as($this->viewer)->getJson('/api/v1/inspections')->assertOk()->assertJsonCount(1, 'data');
    $as($this->viewer)->getJson('/api/v1/evidence')->assertOk()->assertJsonCount(1, 'data');
    $as($this->viewer)->postJson("/api/v1/inspections/{$inspection->id}/submit")->assertForbidden();
});

it('changes the role when an assigned user is assigned again', function (): void {
    $url = "/api/v1/properties/{$this->property->id}/assignments";
    $admin = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $admin()->postJson($url, ['user_id' => $this->manager->id, 'role' => 'cleaner'])->assertCreated();
    $admin()
        ->postJson($url, ['user_id' => $this->manager->id, 'role' => 'property_manager'])
        ->assertOk()
        ->assertJsonPath('data.role', 'property_manager');

    $this->assertDatabaseHas('property_user', ['user_id' => $this->manager->id, 'role' => 'property_manager']);
});
