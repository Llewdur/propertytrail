<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\AuditLog;
use App\Models\CapturePasskey;
use App\Services\CapturePasskeyCeremony;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeCapturePasskeyCeremony;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('passkey');
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());
    $this->ceremony = new FakeCapturePasskeyCeremony();
    app()->instance(CapturePasskeyCeremony::class, $this->ceremony);

    $this->staff = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $property = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Passkey House'))
        ->assertCreated()
        ->json('data');

    $this->lease = ($this->staff)()
        ->postJson('/api/v1/properties/' . $property['id'] . '/leases', [
            'lease_reference' => 'LEASE-40',
            'tenant_name' => 'P. Tenant',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ])
        ->assertCreated()
        ->json('data');

    $this->issueLink = function (): void {
        $url = ($this->staff)()
            ->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link', ['phase' => 'incoming'])
            ->assertCreated()
            ->json('data.url');
        $this->token = (string) parse_url((string) $url, PHP_URL_FRAGMENT);
    };
    ($this->issueLink)();

    $this->tenant = function (?string $unlock = null) {
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        resetTenant();

        return $this->withHeaders([
            'X-Capture-Token' => $this->token,
            'Accept' => 'application/json',
            ...($unlock === null ? [] : ['X-Capture-Unlock' => $unlock]),
        ]);
    };

    $this->credential = fn(string $challenge, string $id = 'phone-1') => [
        'credential' => [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => ['challenge' => $challenge],
        ],
    ];

    $this->photo = fn(?string $unlock = null) => ($this->tenant)($unlock)->post('/api/v1/capture/photos', [
        'file' => UploadedFile::fake()->image('wall.jpg', 10, 10),
        'room_key' => 'room-a',
        'room_name' => 'Kitchen',
    ]);

    $this->register = function (): string {
        $challenge = ($this->tenant)()
            ->postJson('/api/v1/capture/passkey/registration-options')
            ->assertOk()
            ->json('data.options.challenge');

        return (string) ($this->tenant)()
            ->postJson('/api/v1/capture/passkey', ($this->credential)($challenge))
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('data.unlock');
    };
});

it('leaves a link without a passkey working on the link alone', function (): void {
    ($this->tenant)()
        ->getJson('/api/v1/capture')
        ->assertOk()
        ->assertJsonPath('data.passkey_registered', false)
        ->assertJsonPath('data.locked', false);

    ($this->photo)()->assertCreated();
});

it('binds a passkey to the lease and phase, and asks for it before photos on a later visit', function (): void {
    $first = ($this->register)();

    expect(CapturePasskey::query()->allOrganisations()->sole())
        ->lease_id->toBe($this->lease['id'])
        ->phase->toBe('incoming');

    ($this->photo)($first)->assertCreated();

    // A later visit, and even a fresh link for the same capture, starts locked.
    ($this->issueLink)();
    ($this->tenant)()
        ->getJson('/api/v1/capture')
        ->assertOk()
        ->assertJsonPath('data.locked', true)
        ->assertJsonPath('data.tenant_name', '')
        ->assertJsonPath('data.areas', []);
    ($this->photo)()->assertStatus(423)->assertJsonPath('locked', true);
    ($this->photo)($first)->assertStatus(423);
    ($this->tenant)()
        ->post('/api/v1/capture/notes', ['room_key' => 'room-a', 'room_name' => 'Kitchen', 'condition' => 'good'])
        ->assertStatus(423);
    ($this->tenant)()->postJson('/api/v1/capture/passkey/registration-options')->assertStatus(423);

    $challenge = ($this->tenant)()
        ->postJson('/api/v1/capture/unlock/options')
        ->assertOk()
        ->json('data.options.challenge');
    $unlock = ($this->tenant)()
        ->postJson('/api/v1/capture/unlock', ($this->credential)($challenge))
        ->assertOk()
        ->json('data.unlock');

    ($this->tenant)($unlock)->getJson('/api/v1/capture')->assertJsonPath('data.locked', false);
    ($this->photo)($unlock)->assertCreated();

    expect(CapturePasskey::query()->allOrganisations()->sole()->credential)->toBe(['counter' => 1]);
});

it('refuses a wrong passkey, a reused challenge and a second passkey', function (): void {
    ($this->register)();
    $options = fn() => ($this->tenant)()->postJson('/api/v1/capture/unlock/options')->json('data.options.challenge');

    ($this->tenant)()->postJson('/api/v1/capture/unlock', ($this->credential)('forged'))->assertUnprocessable();
    ($this->tenant)()
        ->postJson('/api/v1/capture/unlock', ($this->credential)($options(), 'other-phone'))
        ->assertUnprocessable();

    $challenge = $options();
    ($this->tenant)()->postJson('/api/v1/capture/unlock', ($this->credential)($challenge))->assertOk();
    ($this->tenant)()->postJson('/api/v1/capture/unlock', ($this->credential)($challenge))->assertUnprocessable();

    $unlock = (string) ($this->tenant)()
        ->postJson('/api/v1/capture/unlock', ($this->credential)($options()))
        ->json('data.unlock');
    ($this->tenant)($unlock)->postJson('/api/v1/capture/passkey/registration-options')->assertUnprocessable();

    expect(CapturePasskey::query()->allOrganisations()->count())->toBe(1);
});

it('lets staff who may contribute see and reset a lost passkey, audited', function (): void {
    ($this->register)();
    $base = '/api/v1/leases/' . $this->lease['id'] . '/capture-passkeys';

    ($this->staff)()->getJson($base)->assertOk()->assertJsonPath('data.0.phase', 'incoming');
    ($this->staff)()->deleteJson($base, ['phase' => 'incoming'])->assertOk()->assertJsonPath('data.reset', true);

    expect(CapturePasskey::query()->allOrganisations()->count())
        ->toBe(0)
        ->and(AuditLog::query()->where('event', 'capture_passkey_reset')->value('actor_user_id'))
        ->toBe($this->user->id);

    ($this->photo)()->assertCreated();

    ['organisation' => $otherOrganisation, 'user' => $otherUser] = tenantUser('passkey-other');
    actingAs($otherUser)
        ->withHeader('X-Organisation-Id', (string) $otherOrganisation->id)
        ->deleteJson($base, ['phase' => 'incoming'])
        ->assertNotFound();
});

it('refuses a forged credential through the real WebAuthn validators', function (): void {
    app()->forgetInstance(CapturePasskeyCeremony::class);

    $options = ($this->tenant)()
        ->postJson('/api/v1/capture/passkey/registration-options')
        ->assertOk()
        ->json('data.options');

    expect($options)->toHaveKeys(['challenge', 'rp', 'user', 'pubKeyCredParams']);

    ($this->tenant)()
        ->postJson('/api/v1/capture/passkey', [
            'credential' => [
                'id' => 'AAAA',
                'rawId' => 'AAAA',
                'type' => 'public-key',
                'response' => ['clientDataJSON' => 'e30', 'attestationObject' => 'oA'],
            ],
        ])
        ->assertUnprocessable();

    expect(CapturePasskey::query()->allOrganisations()->count())->toBe(0);
});
