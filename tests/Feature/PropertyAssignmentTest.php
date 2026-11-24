<?php

declare(strict_types=1);

use App\Factories\OrganisationWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Factories\UserWriteFactory;
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
