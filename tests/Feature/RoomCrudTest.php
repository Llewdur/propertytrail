<?php

declare(strict_types=1);

use App\Models\AreaRoom;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('rooms');

    $this->property = actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson('/api/v1/properties', propertyPayload('Room House'))
        ->assertCreated()
        ->json('data');
});

it('creates, lists, updates and deletes a room under a property', function (): void {
    $client = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);
    $propertyId = $this->property['id'];

    $created = $client()->postJson("/api/v1/properties/{$propertyId}/rooms", [
        'name' => 'Main bedroom',
        'room_type' => 'bedroom',
        'sort_order' => 1,
    ])->assertCreated();

    $roomId = $created->json('data.id');

    $client()
        ->getJson("/api/v1/properties/{$propertyId}/rooms")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Main bedroom');

    $client()->putJson("/api/v1/rooms/{$roomId}", [
        'name' => 'Guest bedroom',
    ])->assertOk()->assertJsonPath('data.name', 'Guest bedroom');

    $client()->deleteJson("/api/v1/rooms/{$roomId}")->assertNoContent();
    expect(AreaRoom::query()->find($roomId))->toBeNull();
});

it('rejects an invalid room type', function (): void {
    actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson("/api/v1/properties/{$this->property['id']}/rooms", [
            'name' => 'Garage',
            'room_type' => 'hangar',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('room_type');
});
