<?php

declare(strict_types=1);

use App\Factories\UserWriteFactory;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('team');
});

it('lets an admin invite a manager and the manager accepts once', function (): void {
    $manager = app(UserWriteFactory::class)->create([
        'name' => 'Manager',
        'email' => 'manager-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);

    $response = actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/organisations/{$this->organisation->id}/invitations", [
            'email' => $manager->email,
            'role' => 'property_manager',
        ])
        ->assertCreated();

    $token = $response->json('token');
    expect($token)->toBeString();

    actingAs($manager)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/invitations/{$token}/accept")
        ->assertOk();

    expect($manager->fresh()->roleIn($this->organisation))->toBe('property_manager');

    actingAs($manager)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/invitations/{$token}/accept")
        ->assertConflict();
});

it('refuses to invite an admin', function (): void {
    actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/organisations/{$this->organisation->id}/invitations", [
            'email' => 'new-admin@example.test',
            'role' => 'admin',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('role');
});

it('does not let a manager manage the team', function (): void {
    $manager = app(UserWriteFactory::class)->create([
        'name' => 'Manager',
        'email' => 'manager-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);
    app(UserWriteFactory::class)->attachToOrganisation($manager, $this->organisation, 'property_manager');

    actingAs($manager)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->getJson("/api/v1/organisations/{$this->organisation->id}/members")
        ->assertForbidden();
});
