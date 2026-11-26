<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Factories\InspectionWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\Inspection;
use App\Models\Organisation;
use App\Models\SigningToken;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function deliverySignature(): string
{
    $image = imagecreatetruecolor(120, 40);
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
}

beforeEach(function (): void {
    resetTenant();
    Cache::flush();
    config([
        'services.mail.url' => 'http://mail.test',
        'services.whatsapp.url' => 'http://whatsapp.test',
    ]);
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());

    ['organisation' => $organisation, 'user' => $user] = tenantUser('alpha');
    $this->organisation = $organisation;
    $this->mailFails = false;
    app(CurrentOrganisation::class)->set($organisation->id);
    $property = app(PropertyWriteFactory::class)->create(propertyPayload('Main House'));
    $this->inspection = app(InspectionWriteFactory::class)->create($property, 'incoming', null, null);
    $this->inspection->forceFill(['status' => Inspection::STATUS_SUBMITTED, 'submitted_at' => now()])->save();
    resetTenant();

    $this->asOwner = fn() => withHeader('X-Organisation-Id', (string) $organisation->id)->actingAs($user);
    $this->contact = fn(string $party, array $details) => ($this->asOwner)()
        ->putJson("/api/v1/inspections/{$this->inspection->id}/contacts", ['party' => $party] + $details)
        ->assertOk();
    $this->sign = fn(string $party) => ($this->asOwner)()
        ->postJson("/api/v1/inspections/{$this->inspection->id}/signatures", [
            'party' => $party,
            'signer_name' => ucfirst($party) . ' Person',
            'signature' => deliverySignature(),
        ])
        ->assertCreated();

    Http::fake([
        'mail.test/oauth/token' => Http::response(['access_token' => 'mail-token']),
        'whatsapp.test/oauth/token' => Http::response(['access_token' => 'wa-token']),
        'mail.test/api/v1/instructions' => fn() => (
            $this->mailFails ? Http::response(['message' => 'boom'], 500) : Http::response(['status' => 'queued'], 202)
        ),
        'whatsapp.test/api/v1/instructions' => Http::response(['outcome' => 'dispatched'], 202),
    ]);
});

it('emails the signing link to the party and records the delivery', function (): void {
    ($this->contact)('tenant', ['email' => 'tom@example.com']);

    $response = ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/signing-links/send", [
        'party' => 'tenant',
    ])->assertCreated()->assertJsonPath('data.0.channel', 'mail')->assertJsonPath('data.0.status', 'sent');

    Http::assertSent(static function ($request): bool {
        return (
            $request->url() === 'http://mail.test/api/v1/instructions'
            && $request->hasHeader('Authorization', 'Bearer mail-token')
            && $request['recipients'][0]['address'] === 'tom@example.com'
            && str_contains($request['body']['text'], '/sign/')
            && isset($request['provider']['credential_ref'], $request['bounce_policy']['on_hard_bounce'])
        );
    });

    expect($response->json('data.0.attempts'))->toBe(1);
    expect(SigningToken::query()->allOrganisations()->count())->toBe(1);
    expect(AuditLog::query()->where('event', 'delivery_sent')->exists())->toBeTrue();
});

it('refuses to send, and issues no link, when the party has no contact', function (): void {
    ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/signing-links/send", [
        'party' => 'tenant',
    ])->assertUnprocessable();

    expect(SigningToken::query()->allOrganisations()->count())->toBe(0);
    Http::assertNothingSent();
});

it('emails the locked PDF and a read link to both parties when the report locks', function (): void {
    ($this->contact)('landlord', ['email' => 'lara@example.com']);
    ($this->contact)('tenant', ['email' => 'tom@example.com']);

    ($this->sign)('landlord');
    ($this->sign)('tenant');

    $sent = Http::recorded(static fn($request): bool => $request->url() === 'http://mail.test/api/v1/instructions');
    $recipients = $sent
        ->map(static fn(array $pair): string => $pair[0]['recipients'][0]['address'])
        ->sort()
        ->values()
        ->all();

    expect($recipients)->toBe(['lara@example.com', 'tom@example.com']);

    $first = $sent->first()[0];
    expect($first['attachments'][0]['content_type'])
        ->toBe('application/pdf')
        ->and(base64_decode($first['attachments'][0]['content'], true))
        ->toStartWith('%PDF')
        ->and($first['body']['text'])
        ->toContain('/r/');
    expect(Delivery::query()->allOrganisations()->where('status', 'sent')->count())->toBe(2);
});

it('sends WhatsApp only where the organisation has opted in', function (): void {
    ($this->contact)('landlord', ['email' => 'lara@example.com', 'phone' => '+27821234567']);
    ($this->contact)('tenant', ['email' => 'tom@example.com']);

    ($this->sign)('landlord');
    ($this->sign)('tenant');
    Http::assertNotSent(static fn($request): bool => str_contains($request->url(), 'whatsapp.test/api'));
    expect(Delivery::query()->allOrganisations()->where('channel', 'whatsapp')->count())->toBe(0);
});

it('sends the signing link over WhatsApp on the template path when opted in', function (): void {
    Organisation::query()
        ->whereKey($this->organisation->id)
        ->first()
        ?->forceFill(['settings' => ['delivery' => ['whatsapp' => true]]])
        ->save();
    ($this->contact)('tenant', ['phone' => '+27821234567']);

    ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/signing-links/send", [
        'party' => 'tenant',
    ])->assertCreated()->assertJsonPath('data.0.channel', 'whatsapp');

    Http::assertSent(
        static fn($request): bool => (
            $request->url() === 'http://whatsapp.test/api/v1/instructions'
            && $request->hasHeader('Authorization', 'Bearer wa-token')
            && $request['send_path'] === 'template'
            && $request['recipient'] === '+27821234567'
            && isset($request['provider']['credential_alias'])
        ),
    );
});

it('retries a failing send, then marks it failed and audits it, without undoing the lock', function (): void {
    $this->mailFails = true;
    ($this->contact)('landlord', ['email' => 'lara@example.com']);

    ($this->sign)('landlord');
    ($this->sign)('tenant');

    $delivery = Delivery::query()->allOrganisations()->firstOrFail();
    expect($delivery->status)
        ->toBe('failed')
        ->and($delivery->attempts)
        ->toBe(Delivery::MAX_ATTEMPTS)
        ->and($delivery->last_error)
        ->not->toBeEmpty();
    expect(AuditLog::query()->where('event', 'delivery_failed')->count())->toBe(1);

    ($this->asOwner)()->getJson("/api/v1/inspections/{$this->inspection->id}/report")->assertOk();
    ($this->asOwner)()
        ->getJson("/api/v1/inspections/{$this->inspection->id}/deliveries")
        ->assertOk()
        ->assertJsonPath('data.0.status', 'failed');
});
