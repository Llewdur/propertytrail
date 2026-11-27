<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Factories\UserWriteFactory;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;
use Zekini\Auth\Enums\TermsAcceptanceSource;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('history');
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());

    $this->as = fn($user) => actingAs($user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);
    $this->staff = fn() => ($this->as)($this->user);

    $property = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('History House'))
        ->assertCreated()
        ->json('data');

    $this->room = ($this->staff)()
        ->postJson('/api/v1/properties/' . $property['id'] . '/rooms', [
            'name' => 'Lounge',
            'room_type' => 'living',
            'sort_order' => 1,
        ])
        ->assertCreated()
        ->json('data');

    $this->lease = ($this->staff)()
        ->postJson('/api/v1/properties/' . $property['id'] . '/leases', [
            'lease_reference' => 'LEASE-35',
            'tenant_name' => 'H. Tenant',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ])
        ->assertCreated()
        ->json('data');

    $this->issue = function (): string {
        $url = ($this->staff)()
            ->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link', ['phase' => 'incoming'])
            ->assertCreated()
            ->json('data.url');

        return (string) parse_url((string) $url, PHP_URL_FRAGMENT);
    };

    $this->tenant = function (#[\SensitiveParameter] string $token) {
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        resetTenant();

        return $this->withHeaders(['X-Capture-Token' => $token, 'Accept' => 'application/json']);
    };

    $this->member = function (string $name, string $role) {
        $users = app(UserWriteFactory::class);
        $user = $users->create([
            'name' => $name,
            'email' => strtolower($name) . '-35@example.test',
            'password' => fake()->password(12),
        ]);
        $users->attachToOrganisation($user, $this->organisation, $role);
        $users->acceptTerms($user, TermsAcceptanceSource::Register);

        return $user;
    };
});

it('keeps one tenancy history across links, with the tenant and staff actions and no secrets', function (): void {
    $first = ($this->issue)();
    ($this->tenant)($first)->getJson('/api/v1/capture')->assertOk();
    ($this->tenant)($first)->post('/api/v1/capture/photos', [
        'file' => UploadedFile::fake()->image('wall.jpg', 10, 10),
        'area_room_id' => $this->room['id'],
    ])->assertCreated();

    $second = ($this->issue)();
    ($this->tenant)($second)->getJson('/api/v1/capture')->assertOk();

    $history = ($this->staff)()
        ->getJson('/api/v1/leases/' . $this->lease['id'] . '/history')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json('data');

    $events = array_column($history, 'event');
    $opened = array_values(array_filter(
        $history,
        static fn(array $entry): bool => $entry['event'] === 'capture_link_opened',
    ));

    expect($events)
        ->toContain('capture_link_issued', 'capture_link_revoked', 'capture_link_opened')
        ->and($opened)
        ->toHaveCount(2)
        ->and(array_unique(array_column($opened, 'actor')))
        ->toBe(['tenant_or_system'])
        ->and(collect($history)->where('actor', 'staff')->pluck('actor_user_id')->unique()->all())
        ->toBe([$this->user->id]);

    $json = (string) json_encode($history);

    expect($json)
        ->not->toContain($first)
        ->not->toContain($second)
        ->not->toContain('token_hash')
        ->not->toContain('storage_path');
});

it('shows a tenant only their own actions, never staff activity', function (): void {
    $token = ($this->issue)();
    ($this->tenant)($token)->getJson('/api/v1/capture')->assertOk();

    $history = ($this->tenant)($token)->getJson('/api/v1/capture/history')->assertOk()->json('data');

    expect(array_column($history, 'event'))
        ->toContain('capture_link_opened')
        ->not
        ->toContain('capture_link_issued')
        ->and(array_key_exists('actor_user_id', $history[0]))
        ->toBeFalse();
});

it('shows a staff member their own history and only an admin anyone else', function (): void {
    $agent = ($this->member)('Agent', 'property_manager');
    $outsider = tenantUser('history-other')['user'];

    ($this->issue)();

    ($this->staff)()
        ->getJson('/api/v1/users/' . $this->user->id . '/history')
        ->assertOk()
        ->assertJsonPath('data.0.actor_user_id', $this->user->id);

    ($this->as)($agent)
        ->getJson('/api/v1/users/' . $agent->id . '/history')
        ->assertOk()
        ->assertJsonPath('data', []);
    ($this->as)($agent)->getJson('/api/v1/users/' . $this->user->id . '/history')->assertForbidden();
    ($this->staff)()->getJson('/api/v1/users/' . $outsider->id . '/history')->assertNotFound();
});

it('refuses tenancy history to staff not assigned to the property', function (): void {
    $viewer = ($this->member)('Viewer', 'viewer');

    ($this->as)($viewer)->getJson('/api/v1/leases/' . $this->lease['id'] . '/history')->assertForbidden();
});

it('never lets an audit entry be edited or removed', function (): void {
    ($this->issue)();
    $entry = AuditLog::query()->firstOrFail();

    expect(fn() => $entry->update(['event' => 'tampered']))->toThrow(LogicException::class);
    expect($entry->delete(...))->toThrow(LogicException::class);
});
