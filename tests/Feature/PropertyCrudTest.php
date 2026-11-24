<?php

declare(strict_types=1);

use App\Models\Property;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('crud');
});

it('creates, shows, updates and deletes a property', function (): void {
    $client = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $created = $client()->postJson('/api/v1/properties', propertyPayload('Test House'));
    $created->assertCreated()->assertJsonPath('data.name', 'Test House');

    $id = $created->json('data.id');
    expect(Property::query()->find($id))->not->toBeNull();

    $client()->getJson("/api/v1/properties/{$id}")->assertOk()->assertJsonPath('data.id', $id);

    $client()->putJson("/api/v1/properties/{$id}", [
        'name' => 'Updated House',
    ])->assertOk()->assertJsonPath('data.name', 'Updated House');

    $client()->deleteJson("/api/v1/properties/{$id}")->assertNoContent();
    expect(Property::query()->find($id))->toBeNull();
});

it('validates property payloads', function (): void {
    actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson('/api/v1/properties', [
            'name' => 'Invalid',
            'property_type' => 'spaceship',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('property_type');
});
