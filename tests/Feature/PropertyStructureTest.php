<?php

declare(strict_types=1);

use App\Factories\UserWriteFactory;
use App\Models\AreaRoom;
use App\Models\Property;
use App\Models\PropertyAssignment;
use Zekini\Auth\Enums\TermsAcceptanceSource;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('structure');

    $this->client = fn($user = null, $organisation = null) => actingAs($user ?? $this->user)
        ->withHeader('X-Organisation-Id', (string) ($organisation ?? $this->organisation)->id);

    $this->newSite = fn(string $name) => ($this->client)()
        ->postJson('/api/v1/sites', ['name' => $name, 'site_type' => 'sectional_title'])
        ->assertCreated()
        ->json('data.id');

    $this->area = fn(string $url, string $name, string $type = 'outdoor') => ($this->client)()
        ->postJson($url, ['name' => $name, 'room_type' => $type])
        ->assertCreated()
        ->json('data.id');

    $this->site = ($this->newSite)('Oak Court');

    $this->unit = ($this->client)()
        ->postJson('/api/v1/properties', [...propertyPayload('Unit 1'), 'site_id' => $this->site])
        ->assertCreated()
        ->json('data.id');

    $this->bedroom = ($this->area)("/api/v1/properties/{$this->unit}/rooms", 'Bedroom', 'bedroom');
    $this->garage = ($this->area)("/api/v1/properties/{$this->unit}/rooms", 'Garage', 'garage');
    $this->garden = ($this->area)("/api/v1/sites/{$this->site}/areas", 'Garden');
    $this->pool = ($this->area)("/api/v1/sites/{$this->site}/areas", 'Pool');

    $this->lease = ($this->client)()->postJson("/api/v1/properties/{$this->unit}/leases", [
        'lease_reference' => 'L-1',
        'tenant_name' => 'Tenant',
        'start_date' => '2026-01-01',
    ])->assertCreated()->json('data.id');

    $this->cover = fn(array $ids) => ($this->client)()->putJson("/api/v1/leases/{$this->lease}/areas", [
        'area_ids' => $ids,
    ]);

    $this->inspectedAreas = fn() => collect(
        ($this->client)()
            ->postJson('/api/v1/inspections', [
                'property_id' => $this->unit,
                'lease_id' => $this->lease,
                'phase' => 'incoming',
            ])
            ->assertCreated()
            ->json('data.sections'),
    )
        ->pluck('area_room_id')->all();
});

it('lists a site with its shared areas and units, apart from the unit rooms', function (): void {
    ($this->client)()
        ->getJson('/api/v1/sites')
        ->assertOk()
        ->assertJsonPath('data.0.units.0.id', $this->unit)
        ->assertJsonPath('data.0.areas.0.id', $this->garden)
        ->assertJsonCount(2, 'data.0.areas');

    ($this->client)()->getJson("/api/v1/properties/{$this->unit}/rooms")->assertJsonCount(2, 'data');
});

it('records the owning legal entity of a unit', function (): void {
    ($this->client)()->putJson("/api/v1/properties/{$this->unit}", [
        'owning_entity_name' => 'Oak Holdings (Pty) Ltd',
        'owning_entity_registration_number' => '2020/123456/07',
    ])->assertOk()->assertJsonPath('data.owning_entity_name', 'Oak Holdings (Pty) Ltd')->assertJsonPath('data.owning_entity_registration_number', '2020/123456/07')->assertJsonPath('data.site_id', $this->site);
});

it('inspects the unit rooms when a lease lists no areas', function (): void {
    expect(($this->inspectedAreas)())->toBe([$this->bedroom, $this->garage]);
});

it('inspects exactly the areas a lease covers', function (): void {
    ($this->cover)([$this->bedroom, $this->garden])
        ->assertOk()
        ->assertJsonPath('data.covered_ids', [$this->bedroom, $this->garden])
        ->assertJsonPath('data.is_default', false)
        ->assertJsonCount(4, 'data.available');

    expect(($this->inspectedAreas)())->toBe([$this->bedroom, $this->garden]);
});

it('returns to the unit rooms when the covered list is emptied', function (): void {
    ($this->cover)([$this->pool])->assertOk();

    ($this->cover)([])
        ->assertOk()
        ->assertJsonPath('data.covered_ids', [$this->bedroom, $this->garage])
        ->assertJsonPath('data.is_default', true);
});

it('ignores a covered area after the unit leaves its site', function (): void {
    ($this->cover)([$this->bedroom, $this->garden])->assertOk();
    ($this->client)()->putJson("/api/v1/properties/{$this->unit}", ['site_id' => null])->assertOk();

    expect(($this->inspectedAreas)())->toBe([$this->bedroom]);
});

it('refuses another unit room and another site area on a lease', function (): void {
    $otherSite = ($this->newSite)('Elm House');
    $foreignArea = ($this->area)("/api/v1/sites/{$otherSite}/areas", 'Yard');
    // Written directly: the free plan allows one property through the API.
    $otherUnit = Property::query()->create([
        ...propertyPayload('Unit 2'),
        'organisation_id' => $this->organisation->id,
    ]);
    $foreignRoom = ($this->area)("/api/v1/properties/{$otherUnit->id}/rooms", 'Study');

    foreach ([$foreignArea, $foreignRoom] as $id) {
        ($this->cover)([$id])->assertUnprocessable();
    }
});

it('keeps site areas out of the unit room routes', function (): void {
    ($this->client)()->putJson("/api/v1/rooms/{$this->garden}", ['name' => 'Taken'])->assertNotFound();
    ($this->client)()->deleteJson("/api/v1/rooms/{$this->garden}")->assertNotFound();

    expect(AreaRoom::query()->forOrganisation($this->organisation->id)->find($this->garden)?->name)->toBe('Garden');
});

it('deletes a site area only through its own site', function (): void {
    $otherSite = ($this->newSite)('Elm House');

    ($this->client)()->deleteJson("/api/v1/sites/{$otherSite}/areas/{$this->pool}")->assertNotFound();
    ($this->client)()->deleteJson("/api/v1/sites/{$this->site}/areas/{$this->pool}")->assertNoContent();
});

it('rejects an unknown site type', function (): void {
    ($this->client)()
        ->postJson('/api/v1/sites', ['name' => 'Camp', 'site_type' => 'tent'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('site_type');
});

it('hides sites and areas from another organisation', function (): void {
    ['organisation' => $other, 'user' => $stranger] = tenantUser('stranger');
    resetTenant();
    $client = fn() => ($this->client)($stranger, $other);

    $client()->getJson('/api/v1/sites')->assertOk()->assertJsonCount(0, 'data');
    $client()->postJson("/api/v1/sites/{$this->site}/areas", [
        'name' => 'Gate',
        'room_type' => 'outdoor',
    ])->assertNotFound();
    $client()->deleteJson("/api/v1/sites/{$this->site}/areas/{$this->pool}")->assertNotFound();
    $client()
        ->postJson('/api/v1/properties', [...propertyPayload('Intruder'), 'site_id' => $this->site])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('site_id');
    $client()->getJson("/api/v1/leases/{$this->lease}/areas")->assertNotFound();
});

it('keeps site changes to administrators', function (): void {
    $users = app(UserWriteFactory::class);
    $manager = $users->create([
        'name' => 'Manager',
        'email' => 'manager-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);
    $users->attachToOrganisation($manager, $this->organisation, 'property_manager');
    $users->acceptTerms($manager, TermsAcceptanceSource::Register);
    PropertyAssignment::query()->create([
        'organisation_id' => $this->organisation->id,
        'property_id' => $this->unit,
        'user_id' => $manager->id,
        'role' => 'property_manager',
    ]);
    $client = fn() => ($this->client)($manager);

    $client()->getJson('/api/v1/sites')->assertOk()->assertJsonCount(1, 'data');
    $client()->postJson('/api/v1/sites', ['name' => 'Mine', 'site_type' => 'multi_unit'])->assertForbidden();
    $client()->postJson("/api/v1/sites/{$this->site}/areas", [
        'name' => 'Gate',
        'room_type' => 'outdoor',
    ])->assertForbidden();
    $client()->deleteJson("/api/v1/sites/{$this->site}/areas/{$this->pool}")->assertForbidden();
    $client()->putJson("/api/v1/properties/{$this->unit}", ['site_id' => null])->assertForbidden();
    $client()->putJson("/api/v1/properties/{$this->unit}", ['site_id' => $this->site])->assertOk();
    $client()->putJson("/api/v1/leases/{$this->lease}/areas", ['area_ids' => [$this->garden]])->assertOk();
});
