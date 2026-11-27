<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Contracts\Adapters\WhatsAppAdapterInterface;
use App\Models\AuditLog;
use App\Models\CaptureReminder;
use App\Models\CaptureSignOff;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    // 10:00 in Johannesburg: inside the reminder window.
    $this->travelTo(Carbon::parse('2026-11-27 08:00:00', 'UTC'));
    config([
        'services.push.enabled' => true,
        'services.push.url' => 'https://push.test',
        'services.push.token' => 'push-token',
    ]);

    $this->pushStatus = 'pending';
    Http::fake([
        'push.test/api/v1/opt-in-links' => Http::response(['data' => [
            'link_id' => 'oil_1',
            'short_url' => 'https://push.test/o/CODE',
            'expires_at' => '2026-12-10T08:00:00Z',
            'status' => 'pending',
        ]], 201),
        'push.test/api/v1/opt-in-links/oil_1' => fn() => Http::response(['data' => [
            'link_id' => 'oil_1',
            'status' => $this->pushStatus,
            'device_class' => $this->pushStatus === 'completed' ? 'android' : null,
            'subscriptions' => $this->pushStatus === 'completed'
                ? [['subscription_id' => 'psub_1', 'state' => 'active', 'device_class' => 'android']]
                : [],
        ]]),
        'push.test/api/v1/instructions' => Http::response(['data' => ['state' => 'accepted']], 202),
    ]);

    $this->whatsApp = new class implements WhatsAppAdapterInterface {
        /** @var list<array{key: string, recipient: string, message: string}> */
        public array $sent = [];

        public function sendMessage(string $idempotencyKey, string $recipient, string $message): array
        {
            $this->sent[] = ['key' => $idempotencyKey, 'recipient' => $recipient, 'message' => $message];

            return [];
        }
    };
    app()->instance(WhatsAppAdapterInterface::class, $this->whatsApp);

    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('notify');
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());

    $staff = actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);
    $property = $staff->postJson('/api/v1/properties', propertyPayload('Notify House'))->assertCreated()->json('data');
    $this->lease = $staff
        ->postJson('/api/v1/properties/' . $property['id'] . '/leases', [
            'lease_reference' => 'LEASE-28',
            'tenant_name' => 'N. Tenant',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ])
        ->assertCreated()
        ->json('data');
    $url = $staff
        ->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link', ['phase' => 'incoming'])
        ->assertCreated()
        ->json('data.url');
    $this->token = (string) parse_url((string) $url, PHP_URL_FRAGMENT);

    $this->tenant = function () {
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        resetTenant();

        return $this->withHeaders(['X-Capture-Token' => $this->token, 'Accept' => 'application/json']);
    };

    $this->upload = fn() => ($this->tenant)()->post('/api/v1/capture/photos', [
        'room_key' => 'hall',
        'room_name' => 'Hall',
        'file' => UploadedFile::fake()->image('hall.jpg', 10, 10),
    ]);

    $this->remind = fn() => $this->artisan('capture:send-reminders')->assertSuccessful();
    $this->pushes = fn() => Http::recorded(static fn(Request $request): bool => str_ends_with(
        $request->url(),
        '/api/v1/instructions',
    ))->values();
});

it('asks for notifications through a Push-hosted link and refuses photos until they are on', function (): void {
    ($this->tenant)()
        ->getJson('/api/v1/capture')
        ->assertOk()
        ->assertJsonPath('data.notifications.required', true)
        ->assertJsonPath('data.notifications.status', 'pending')
        ->assertJsonPath('data.notifications.opt_in_url', 'https://push.test/o/CODE');

    ($this->upload)()->assertConflict()->assertJsonPath('notifications_required', true);

    Http::assertSent(
        static fn(Request $request): bool => (
            $request->url() === 'https://push.test/api/v1/opt-in-links'
            && $request['participant_reference'] === 'ptr-lease-' . test()->lease['id'] . '-incoming'
            && $request->hasHeader('Authorization', 'Bearer push-token')
        ),
    );

    $this->pushStatus = 'completed';

    ($this->tenant)()
        ->getJson('/api/v1/capture')
        ->assertJsonPath('data.notifications.status', 'subscribed')
        ->assertJsonPath('data.notifications.opt_in_url', null);
    ($this->upload)()->assertCreated();

    expect(AuditLog::query()->where('event', 'capture_notifications_on')->count())->toBe(1);
});

it('lets a tenant who cannot accept notifications be reminded on WhatsApp instead', function (): void {
    ($this->tenant)()->getJson('/api/v1/capture')->assertOk();

    ($this->tenant)()->postJson('/api/v1/capture/notifications/fallback', ['phone' => 'nope'])->assertUnprocessable();
    ($this->tenant)()
        ->postJson('/api/v1/capture/notifications/fallback', ['phone' => '+27821234567'])
        ->assertOk()
        ->assertJsonPath('data.status', 'fallback');

    ($this->upload)()->assertCreated();

    $this->travel(25)->hours();
    ($this->remind)();

    expect($this->whatsApp->sent)
        ->toHaveCount(1)
        ->and($this->whatsApp->sent[0]['recipient'])
        ->toBe('+27821234567')
        ->and($this->whatsApp->sent[0]['message'])
        ->toContain('/capture#' . $this->token);
});

it('does not gate capture while Fleet Push is off', function (): void {
    config(['services.push.enabled' => false]);

    ($this->tenant)()->getJson('/api/v1/capture')->assertJsonPath('data.notifications.status', 'off');
    ($this->upload)()->assertCreated();
    Http::assertNothingSent();
});

it('pushes a reminder once a day, outside quiet hours, carrying the link and a stable key', function (): void {
    $this->pushStatus = 'completed';
    ($this->tenant)()->getJson('/api/v1/capture');
    ($this->tenant)()->getJson('/api/v1/capture');

    ($this->remind)();
    expect(($this->pushes)())->toHaveCount(0);

    // 21:00 in Johannesburg: quiet hours.
    $this->travelTo(Carbon::parse('2026-11-28 19:00:00', 'UTC'));
    ($this->remind)();
    expect(($this->pushes)())->toHaveCount(0);

    $this->travelTo(Carbon::parse('2026-11-29 08:00:00', 'UTC'));
    ($this->remind)();
    ($this->remind)();

    $pushes = ($this->pushes)();
    $reminder = CaptureReminder::query()->allOrganisations()->sole();

    expect($pushes)
        ->toHaveCount(1)
        ->and($pushes[0][0]['idempotency_key'])
        ->toBe('ptr-capture-reminder-' . $reminder->id . '-1')
        ->and($pushes[0][0]['participant_references'])
        ->toBe(['ptr-lease-' . $this->lease['id'] . '-incoming'])
        ->and($pushes[0][0]['payload']['url'])
        ->toEndWith('/capture#' . $this->token)
        ->and($reminder->reminders_sent)
        ->toBe(1);
});

it('stops reminding once the tenant signs off or the limit is reached', function (): void {
    $this->pushStatus = 'completed';
    ($this->tenant)()->getJson('/api/v1/capture');
    ($this->tenant)()->getJson('/api/v1/capture');
    $reminder = CaptureReminder::query()->allOrganisations()->sole();

    $reminder->forceFill(['reminders_sent' => 7])->save();
    $this->travel(2)->days();
    ($this->remind)();

    expect($reminder->refresh()->stop_reason)->toBe('limit')->and(($this->pushes)())->toHaveCount(0);

    $reminder->forceFill(['reminders_sent' => 0, 'stopped_at' => null, 'stop_reason' => null])->save();
    CaptureSignOff::query()->forceCreate([
        'organisation_id' => $this->organisation->id,
        'lease_id' => $this->lease['id'],
        'phase' => 'incoming',
        'version' => 1,
        'signer_name' => 'N. Tenant',
        'signature_image' => 'x',
        'declaration_version' => 'v1',
        'declarations' => [],
        'content_hash' => str_repeat('a', 64),
        'content' => [],
        'phone_unlocked' => false,
        'signed_at' => now(),
    ]);
    ($this->remind)();

    expect($reminder->refresh()->stop_reason)->toBe('complete')->and(($this->pushes)())->toHaveCount(0);
});
