<?php

declare(strict_types=1);

use App\Factories\OrganisationWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Factories\UserWriteFactory;
use App\Models\Property;
use App\Support\Tenancy\CurrentOrganisation;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn() => resetTenant());

it('only returns properties from the resolved organisation', function (): void {
    ['organisation' => $alpha, 'user' => $user] = tenantUser('alpha');

    $beta = app(OrganisationWriteFactory::class)->create(['name' => 'Organisation Beta']);
    app(UserWriteFactory::class)->attachToOrganisation($user, $beta, 'admin');

    app(CurrentOrganisation::class)->set($alpha->id);
    $alphaProperty = app(PropertyWriteFactory::class)->create(propertyPayload('Alpha House'));

    app(CurrentOrganisation::class)->set($beta->id);
    $betaProperty = app(PropertyWriteFactory::class)->create(propertyPayload('Beta House'));

    resetTenant();

    withHeader('X-Organisation-Id', (string) $alpha->id)
        ->actingAs($user)
        ->getJson('/api/v1/properties')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $alphaProperty->id)
        ->assertJsonMissing(['name' => 'Beta House']);

    actingAs($user)
        ->withHeader('X-Organisation-Id', (string) $beta->id)
        ->getJson('/api/v1/properties')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $betaProperty->id);
});

it('cannot show or mutate another tenant property', function (): void {
    ['organisation' => $alpha, 'user' => $user] = tenantUser('alpha');

    $beta = app(OrganisationWriteFactory::class)->create(['name' => 'Organisation Beta']);
    app(CurrentOrganisation::class)->set($beta->id);
    $betaProperty = app(PropertyWriteFactory::class)->create(propertyPayload('Beta House'));

    resetTenant();

    $client = actingAs($user)->withHeader('X-Organisation-Id', (string) $alpha->id);

    $client->getJson("/api/v1/properties/{$betaProperty->id}")->assertNotFound();
    $client->putJson("/api/v1/properties/{$betaProperty->id}", ['name' => 'Hacked'])->assertNotFound();
    $client->deleteJson("/api/v1/properties/{$betaProperty->id}")->assertNotFound();

    expect(Property::withoutGlobalScopes()->findOrFail($betaProperty->id)->name)->toBe('Beta House');
});
