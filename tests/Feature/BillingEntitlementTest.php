<?php

declare(strict_types=1);

use App\Exceptions\PlanLimitExceeded;
use App\Factories\PropertyWriteFactory;
use App\Models\Inspection;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Services\Billing\EntitlementService;
use App\Services\SigningService;
use App\Support\FleetServiceToken;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\withHeader;

uses(RefreshDatabase::class);

function billingClient(App\Models\User $user, int $organisationId): mixed
{
    return withHeader('X-Organisation-Id', (string) $organisationId)->actingAs($user);
}

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $organisation, 'user' => $user] = tenantUser('billing');
    $this->organisation = $organisation;
    $this->user = $user;
});

afterEach(function (): void {
    FleetServiceToken::forget('accounting');
    resetTenant();
});

it('stops a free organisation at one inspection and offers the next plan', function (): void {
    app(CurrentOrganisation::class)->set($this->organisation->id);
    app(PropertyWriteFactory::class)->create(propertyPayload('Billing House'));
    resetTenant();

    $client = billingClient($this->user, $this->organisation->id);
    $client->postJson('/api/v1/inspections', ['property_id' => 1, 'phase' => 'incoming'])->assertCreated();

    $response = $client
        ->postJson('/api/v1/inspections', ['property_id' => 1, 'phase' => 'incoming'])
        ->assertPaymentRequired()
        ->assertJsonPath('code', 'plan_limit')
        ->assertJsonPath('upgrade.plan', 'starter')
        ->assertJsonPath('upgrade.price_monthly_cents', 14_900);

    expect($response->json('message'))->toContain('Starter');
    expect(UsageRecord::query()->forOrganisation($this->organisation->id)->where('kind', 'inspection')->count())
        ->toBe(1);
});

it('counts deleted drafts against the inspection allowance', function (): void {
    app(CurrentOrganisation::class)->set($this->organisation->id);
    $property = app(PropertyWriteFactory::class)->create(propertyPayload('Billing House'));
    $inspection = app(App\Factories\InspectionWriteFactory::class)->create($property, 'incoming', null, null);
    $inspection->delete();
    resetTenant();

    billingClient($this->user, $this->organisation->id)
        ->postJson('/api/v1/inspections', ['property_id' => $property->id, 'phase' => 'incoming'])
        ->assertPaymentRequired();
});

it('enforces property limits without resetting on deletion', function (): void {
    app(CurrentOrganisation::class)->set($this->organisation->id);
    $property = app(PropertyWriteFactory::class)->create(propertyPayload('First House'));
    $property->delete();
    resetTenant();

    billingClient($this->user, $this->organisation->id)
        ->postJson('/api/v1/properties', propertyPayload('Second House'))
        ->assertPaymentRequired()
        ->assertJsonPath('code', 'plan_limit');
});

it('enforces the monthly signing-link limit and leaves existing links alone', function (): void {
    config(['plans.plans.starter.signing_link_limit' => 1]);
    $settings = $this->organisation->settings;
    $settings['plan'] = 'starter';
    $this->organisation->settings = $settings;
    $this->organisation->save();

    app(CurrentOrganisation::class)->set($this->organisation->id);
    $property = app(PropertyWriteFactory::class)->create(propertyPayload('Signing House'));
    $factory = app(App\Factories\InspectionWriteFactory::class);
    $first = $factory->create($property, 'incoming', null, null);
    $second = $factory->create($property, 'outgoing', null, null);
    $first->update(['status' => Inspection::STATUS_SUBMITTED]);
    $second->update(['status' => Inspection::STATUS_SUBMITTED]);
    $signing = app(SigningService::class);

    $issued = $signing->issueLink($first, 'landlord');
    expect($issued['token'])->not->toBe('');

    try {
        $signing->issueLink($second, 'landlord');
        $this->fail('Expected the signing-link limit to be enforced.');
    } catch (PlanLimitExceeded $exception) {
        expect($exception->getMessage())->toContain('signing link');
    }
});

it('accepts a signed webhook, changes only that tenant and rejects a bad signature', function (): void {
    config(['services.accounting.webhook_secret' => 'webhook-secret']);

    $beta = app(App\Factories\OrganisationWriteFactory::class)->create(['name' => 'Organisation Beta']);
    $payload = [
        'event_type' => 'customer_upgraded',
        'data' => [
            'organisation_uuid' => $this->organisation->uuid,
            'plan_code' => 'landlord',
            'customer_id' => 987,
        ],
    ];
    $signature = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), 'webhook-secret');

    $this->postJson('/api/v1/billing/webhook', $payload, [
        'X-Fleet-Signature' => $signature,
    ])->assertOk()->assertJsonPath('data.received', true);

    $this->organisation->refresh();
    $beta->refresh();
    expect($this->organisation->settings['plan'])->toBe('landlord');
    expect($beta->settings['plan'] ?? 'free')->toBe('free');
    expect(Subscription::query()->forOrganisation($this->organisation->id)->first()->status)->toBe('active');

    $this->postJson('/api/v1/billing/webhook', $payload, ['X-Fleet-Signature' => 'bad'])->assertForbidden();
});

it('suspends entitlement on cancellation and restores it on payment received', function (): void {
    app(CurrentOrganisation::class)->set($this->organisation->id);
    app(PropertyWriteFactory::class)->create(propertyPayload('First House'));
    resetTenant();

    $billing = app(EntitlementService::class);
    $suspend = [
        'event_type' => 'subscription_cancelled',
        'data' => ['organisation_uuid' => $this->organisation->uuid],
    ];
    $billing->applySubscriptionEvent($suspend);

    $this->organisation->refresh();
    expect($this->organisation->settings['plan'])->toBe('free');
    expect($billing->status($this->organisation))->toBe('suspended');

    $billing->applySubscriptionEvent([
        'event_type' => 'payment_received',
        'data' => ['organisation_uuid' => $this->organisation->uuid],
    ]);

    $this->organisation->refresh();
    expect($this->organisation->settings['plan'])->toBe('free');
    expect($billing->status($this->organisation))->toBe('active');
});

it('exposes the plan catalogue and usage without storing financial records', function (): void {
    app(CurrentOrganisation::class)->set($this->organisation->id);
    app(PropertyWriteFactory::class)->create(propertyPayload('Billing House'));
    resetTenant();

    billingClient($this->user, $this->organisation->id)
        ->getJson('/api/v1/billing/entitlement')
        ->assertOk()
        ->assertJsonPath('data.plan.code', 'free')
        ->assertJsonPath('data.usage.properties', 1)
        ->assertJsonPath('data.limits.watermark', true)
        ->assertJsonPath('data.plans.0.code', 'free');

    foreach ([
        'invoices',
        'invoice_lines',
        'credit_notes',
        'statements',
        'ledger_entries',
        'payment_records',
    ] as $table) {
        expect(Illuminate\Support\Facades\Schema::hasTable($table))->toBeFalse();
    }
});

it('sends a usage event to Fleet Accounting when configured and queues failures', function (): void {
    config([
        'services.accounting.url' => 'https://accounting.test',
        'services.accounting.client_id' => 'client-id',
        'services.accounting.client_secret' => 'client-secret',
    ]);
    FleetServiceToken::forget('accounting');
    Http::fake([
        'https://accounting.test/oauth/token' => Http::response(['access_token' => 'fac-token'], 200),
        'https://accounting.test/api/v1/machine/*' => Http::response(['data' => ['id' => 1]], 201),
    ]);

    app(CurrentOrganisation::class)->set($this->organisation->id);
    app(PropertyWriteFactory::class)->create(propertyPayload('Usage House'));
    resetTenant();

    Http::assertSent(
        fn($request): bool => (
            str_contains($request->url(), '/api/v1/machine/events')
            && $request['event_type'] === 'usage_recorded'
            && $request['payload']['usage']['metric'] === 'property'
        ),
    );
    expect(App\Models\BillingEventOutbox::query()->count())->toBe(0);
});

it('creates one Fleet Accounting customer per organisation and reuses it', function (): void {
    config([
        'services.accounting.url' => 'https://accounting.test',
        'services.accounting.client_id' => 'client-id',
        'services.accounting.client_secret' => 'client-secret',
        'services.accounting.customer_email' => 'billing@example.test',
    ]);
    FleetServiceToken::forget('accounting');
    Http::fake([
        'https://accounting.test/oauth/token' => Http::response(['access_token' => 'fac-token'], 200),
        'https://accounting.test/api/v1/machine/customers' => Http::response(['data' => ['id' => 4242]], 201),
    ]);

    $billing = app(EntitlementService::class);
    expect($billing->ensureCustomer($this->organisation))->toBe('4242');
    expect($billing->ensureCustomer($this->organisation->refresh()))->toBe('4242');
    expect($this->organisation->refresh()->accounting_customer_id)->toBe('4242');

    $customerRequests = collect(Http::recorded())
        ->filter(fn(array $pair): bool => str_contains($pair[0]->url(), '/api/v1/machine/customers'));
    expect($customerRequests)->toHaveCount(1);

    Http::assertSent(
        fn($request): bool => (
            str_contains($request->url(), '/api/v1/machine/customers')
            && $request['external_reference'] === 'propertytrail:organisation:' . $this->organisation->uuid
            && $request['primary_contact_email'] === 'billing@example.test'
        ),
    );
});

it('queues a failed Fleet Accounting event for retry', function (): void {
    config([
        'services.accounting.url' => 'https://accounting.test',
        'services.accounting.client_id' => 'client-id',
        'services.accounting.client_secret' => 'client-secret',
    ]);
    FleetServiceToken::forget('accounting');
    Http::fake([
        'https://accounting.test/oauth/token' => Http::response(['access_token' => 'fac-token'], 200),
        'https://accounting.test/api/v1/machine/customers' => Http::response(['data' => ['id' => 1]], 201),
        'https://accounting.test/api/v1/machine/events' => Http::sequence()
            ->push(['message' => 'down'], 503)
            ->push(['data' => ['id' => 2]], 201),
    ]);

    app(CurrentOrganisation::class)->set($this->organisation->id);
    app(PropertyWriteFactory::class)->create(propertyPayload('Outbox House'));
    resetTenant();

    $outbox = App\Models\BillingEventOutbox::query()->forOrganisation($this->organisation->id)->first();
    expect($outbox)->not->toBeNull();
    expect($outbox->status)->toBe(App\Models\BillingEventOutbox::STATUS_PENDING);

    $this->travel(6)->minutes();
    expect(app(EntitlementService::class)->flushOutbox())->toBe(1);

    $outbox->refresh();
    expect($outbox->status)->toBe(App\Models\BillingEventOutbox::STATUS_SENT);
    expect($outbox->attempts)->toBe(1);

    $eventRequests = collect(Http::recorded())
        ->filter(fn(array $pair): bool => str_contains($pair[0]->url(), '/api/v1/machine/events'));
    expect($eventRequests)->toHaveCount(2);
});
