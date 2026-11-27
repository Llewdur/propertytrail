<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\AreaRoom;
use App\Models\AuditLog;
use App\Models\EvidenceLink;
use App\Models\Lease;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('guided');
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());

    $this->staff = function () {
        $this->flushHeaders();

        return actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);
    };

    $this->property = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Guided House'))
        ->assertCreated()
        ->json('data');

    $this->lease = ($this->staff)()
        ->postJson('/api/v1/properties/' . $this->property['id'] . '/leases', [
            'lease_reference' => 'LEASE-27',
            'tenant_name' => 'T. Tenant',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ])
        ->assertCreated()
        ->json('data');

    $url = ($this->staff)()
        ->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link', ['phase' => 'incoming'])
        ->assertCreated()
        ->json('data.url');
    $this->token = (string) parse_url((string) $url, PHP_URL_FRAGMENT);

    $this->upload = function (array $fields, int $width = 10) {
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        resetTenant();

        return $this->withHeaders([
            'X-Capture-Token' => $this->token,
            'Accept' => 'application/json',
        ])->post('/api/v1/capture/photos', ['file' => UploadedFile::fake()->image('p.jpg', $width, 10), ...$fields]);
    };

    $this->rooms = fn() => AreaRoom::query()->allOrganisations()->orderBy('sort_order')->get();
});

it('creates the room a tenant names with its first photo and keeps the corner order', function (): void {
    $hall = ['room_key' => 'hall-key', 'room_name' => 'Hall'];

    ($this->upload)([
        ...$hall,
        'corner' => 1,
        'shot' => 'corner',
        'taken_at' => '2026-01-05T10:00:00+02:00',
    ])->assertCreated();
    ($this->upload)([...$hall, 'corner' => 2, 'shot' => 'nearest'], 11)->assertCreated();
    ($this->upload)([
        'room_key' => 'garden-key',
        'room_name' => 'Garden',
        'room_outdoor' => '1',
        'adjoins_room_key' => 'hall-key',
        'shot' => 'extra',
        'taken_at' => '2999-01-01T00:00:00Z',
    ], 12)->assertCreated();

    [$first, $second] = ($this->rooms)()->all();
    $photos = EvidenceLink::query()->allOrganisations()->orderBy('id')->get();

    expect(($this->rooms)()->pluck('name')->all())
        ->toBe(['Hall', 'Garden'])
        ->and($second->room_type)
        ->toBe('outdoor')
        ->and($second->adjoins_area_room_id)
        ->toBe($first->id)
        ->and($photos->pluck('label')->all())
        ->toBe(['Corner 1', 'Corner 2 (nearest spot)', 'Extra photo'])
        ->and($photos[0]->taken_at?->getTimestamp())
        ->toBe(strtotime('2026-01-05T08:00:00+00:00'))
        ->and($photos[2]->taken_at?->isFuture())
        ->toBeFalse();
});

it('adds a new room to a lease that lists its areas without dropping the listed ones', function (): void {
    $listed = ($this->staff)()
        ->postJson('/api/v1/properties/' . $this->property['id'] . '/rooms', [
            'name' => 'Kitchen',
            'room_type' => 'kitchen',
        ])
        ->assertCreated()
        ->json('data.id');
    ($this->staff)()->putJson('/api/v1/leases/' . $this->lease['id'] . '/areas', ['area_ids' => [$listed]])->assertOk();

    $created = ($this->upload)(['room_key' => 'k2', 'room_name' => 'Pantry'])
        ->assertCreated()
        ->json('data.area_room_id');

    ($this->staff)()
        ->getJson('/api/v1/leases/' . $this->lease['id'] . '/areas')
        ->assertOk()
        ->assertJsonPath('data.covered_ids', [$listed, $created]);
});

it('refuses a photo that names no room', function (): void {
    ($this->upload)([])->assertUnprocessable();
    ($this->upload)(['room_key' => 'nameless'])->assertUnprocessable();

    expect(($this->rooms)())->toHaveCount(0);
});

it('lets staff split and merge captured rooms and records the history', function (): void {
    $photoIds = [];

    foreach ([10, 11] as $width) {
        $photoIds[] = ($this->upload)(['room_key' => 'a', 'room_name' => 'Lounge'], $width)
            ->assertCreated()
            ->json('data.id');
    }

    $lounge = ($this->rooms)()->sole();

    $dining = ($this->staff)()
        ->postJson("/api/v1/rooms/{$lounge->id}/split", ['name' => 'Dining', 'photo_ids' => [$photoIds[1]]])
        ->assertCreated()
        ->json('data.id');

    ($this->staff)()->postJson("/api/v1/rooms/{$lounge->id}/split", [
        'name' => 'Nope',
        'photo_ids' => [$photoIds[1]],
    ])->assertUnprocessable();
    ($this->staff)()->postJson("/api/v1/rooms/{$dining}/merge", ['into_room_id' => $dining])->assertUnprocessable();
    ($this->staff)()->postJson("/api/v1/rooms/{$lounge->id}/merge", ['into_room_id' => $dining])->assertOk();

    $merged = ($this->rooms)()->sole();

    expect($merged->id)
        ->toBe($dining)
        ->and($merged->capture_key)
        ->toBe('a')
        ->and(
            EvidenceLink::query()
                ->allOrganisations()
                ->pluck('area_room_id')
                ->unique()
                ->values()
                ->all(),
        )
        ->toBe([$dining])
        ->and(AuditLog::query()->whereIn('event', ['room_split', 'room_merged'])->orderBy('id')->pluck('event')->all())
        ->toBe(['room_split', 'room_merged']);
});

it('keeps another organisation away from merge and split', function (): void {
    $roomId = ($this->upload)(['room_key' => 'a', 'room_name' => 'Lounge'])->assertCreated()->json('data.area_room_id');
    ['organisation' => $other, 'user' => $stranger] = tenantUser('guided-other');
    $this->flushHeaders();

    actingAs($stranger)
        ->withHeader('X-Organisation-Id', (string) $other->id)
        ->postJson("/api/v1/rooms/{$roomId}/split", ['name' => 'Mine', 'photo_ids' => [1]])
        ->assertNotFound();
});
