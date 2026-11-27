<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\AreaRoom;
use App\Models\CaptureIssue;
use App\Models\CaptureRoomRating;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('notes');
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());

    $this->staff = function () {
        $this->flushHeaders();

        return actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);
    };

    $this->property = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Noted House'))
        ->assertCreated()
        ->json('data');

    $this->lease = ($this->staff)()
        ->postJson('/api/v1/properties/' . $this->property['id'] . '/leases', [
            'lease_reference' => 'LEASE-33',
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

    $this->tenant = function (string $path, array $fields) {
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        resetTenant();

        return $this->withHeaders([
            'X-Capture-Token' => $this->token,
            'Accept' => 'application/json',
        ])->post('/api/v1/capture/' . $path, $fields);
    };

    $this->hall = ['room_key' => 'hall', 'room_name' => 'Hall'];
    $this->crack = [...$this->hall, 'issue_key' => 'crack', 'issue_kind' => 'damaged', 'issue_note' => 'Cracked tile'];
});

it('keeps one rating and one issue however often the phone retries, and shows staff who said it', function (): void {
    ($this->tenant)('notes', [...$this->hall, 'condition' => 'good'])->assertCreated();
    ($this->tenant)('notes', [...$this->hall, 'condition' => 'poor'])->assertCreated();
    ($this->tenant)('notes', $this->crack)->assertCreated();
    ($this->tenant)('notes', $this->crack)->assertCreated();
    $photoId = ($this->tenant)('photos', [
        ...$this->hall,
        'shot' => 'closeup',
        'issue_key' => 'crack',
        'file' => UploadedFile::fake()->image('c.jpg', 10, 10),
    ])
        ->assertCreated()
        ->json('data.id');

    $group = ($this->staff)()
        ->getJson('/api/v1/properties/' . $this->property['id'] . '/lease-photos')
        ->assertOk()
        ->assertJsonCount(1, 'data.groups')
        ->assertJsonCount(1, 'data.groups.0.ratings')
        ->assertJsonCount(1, 'data.groups.0.issues')
        ->json('data.groups.0');

    expect($group['ratings'][0]['condition'])
        ->toBe('Poor')
        ->and($group['issues'][0])
        ->toMatchArray([
            'kind' => 'damaged',
            'note' => 'Cracked tile',
            'recorded_by' => 'T. Tenant',
            'photo_ids' => [$photoId],
        ])
        ->and($group['photos'][0]['label'])
        ->toBe('Close-up: Damaged');
});

it('refuses a bad note and saves nothing', function (array $fields): void {
    ($this->tenant)('notes', $fields)->assertUnprocessable();

    expect(CaptureIssue::query()->allOrganisations()->count())
        ->toBe(0)
        ->and(CaptureRoomRating::query()->allOrganisations()->count())
        ->toBe(0);
})->with([
    'unknown rating' => [['room_key' => 'a', 'room_name' => 'Hall', 'condition' => 'awful']],
    'unknown kind' => [[
        'room_key' => 'a',
        'room_name' => 'Hall',
        'issue_key' => 'i',
        'issue_kind' => 'dirty',
        'issue_note' => 'x',
    ]],
    'no note' => [['room_key' => 'a', 'room_name' => 'Hall', 'issue_key' => 'i', 'issue_kind' => 'broken']],
    'uncovered room' => [['area_room_id' => 999999, 'condition' => 'good']],
]);

it('refuses a note once the link is withdrawn', function (): void {
    ($this->staff)()->deleteJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link')->assertOk();

    ($this->tenant)('notes', [...$this->hall, 'condition' => 'good'])->assertGone();
});

it('keeps the tenant notes when staff merge the room into another', function (): void {
    ($this->tenant)('notes', [...$this->hall, 'condition' => 'good'])->assertCreated();
    ($this->tenant)('notes', $this->crack)->assertCreated();
    ($this->tenant)('notes', [
        'room_key' => 'passage',
        'room_name' => 'Passage',
        'condition' => 'fair',
    ])->assertCreated();
    [$hall, $passage] = AreaRoom::query()->allOrganisations()->orderBy('id')->pluck('id')->all();

    ($this->staff)()->postJson("/api/v1/rooms/{$hall}/merge", ['into_room_id' => $passage])->assertOk();

    expect(CaptureIssue::query()->allOrganisations()->sole()->area_room_id)
        ->toBe($passage)
        ->and(CaptureRoomRating::query()->allOrganisations()->sole()->condition)
        ->toBe('fair');
});
