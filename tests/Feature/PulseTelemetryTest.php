<?php

declare(strict_types=1);

use App\Contracts\Adapters\PulseAdapterInterface;
use App\Models\AuthEvent;
use Illuminate\Http\Client\Request as OutboundRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Zekini\PulseSdk\Audit\ApiCallRecorder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function switchPulseOn(): void
{
    config([
        'services.pulse.write_key' => 'test-write-key',
        'services.pulse.api_base' => 'https://pulse.test',
        'pulse-sdk.enabled' => true,
        'pulse-sdk.base_url' => 'https://pulse.test',
        'pulse-sdk.write_key' => 'test-write-key',
        'pulse-sdk.audit.endpoint' => 'https://pulse.test',
        'pulse-sdk.audit.write_key' => 'test-write-key',
    ]);

    Http::fake(['pulse.test/*' => Http::response([], 202)]);
}

/** @return list<array<string, mixed>> */
function sentToPulse(string $path): array
{
    return Http::recorded(static fn(OutboundRequest $request): bool => str_ends_with($request->url(), $path))
        ->map(static fn(array $pair): array => $pair[0]->data())
        ->values()
        ->all();
}

/** @return list<array<string, mixed>> */
function auditRows(): array
{
    return array_merge([], ...array_column(sentToPulse('/api-calls'), 'calls'));
}

it('serves no tag and widens no policy without a write key', function (string $path): void {
    $response = get($path)->assertOk()->assertDontSee('pulse.js', false);

    expect($response->headers->get('Content-Security-Policy'))->not->toContain('pulse');
})->with(['/app', '/', '/privacy', '/terms']);

it('serves the tag with the masking settings and lets the policy run it', function (string $path): void {
    switchPulseOn();

    $response = get($path)
        ->assertOk()
        ->assertSee('<script src="https://pulse.test/pulse.js" defer></script>', false)
        ->assertSee('"captureClickText":false,"stripQuery":true,"stripFragment":true', false)
        ->assertSee('"\/sign\/*","\/r\/*","\/api\/v1\/sign\/*","\/api\/v1\/invitations\/*"', false);

    $policy = (string) $response->headers->get('Content-Security-Policy');
    expect($policy)->toContain('connect-src')->toContain('https://pulse.test');

    preg_match('/<script>(window\.pulseConfig=.*?)<\/script>/', (string) $response->getContent(), $inline);

    // A policy runs the inline settings either by allowing all inline script or by their exact hash.
    expect(
        str_contains($policy, "'unsafe-inline' https://pulse.test;")
        || str_contains($policy, "'sha256-" . base64_encode(hash('sha256', $inline[1], true)) . "'"),
    )->toBeTrue();
})->with(['/app', '/', '/privacy', '/terms']);

it('keeps inline script allowed on the app page, which a hash would cancel', function (): void {
    switchPulseOn();

    expect((string) get('/app')->headers->get('Content-Security-Policy'))
        ->toContain("script-src 'self' 'unsafe-inline' https://pulse.test;")
        ->not->toContain('sha256-');
});

it('sends a server error with the route template, never the token in the path', function (): void {
    switchPulseOn();
    // Under build/, which the app page's catch-all route leaves alone.
    Route::get('build/r/{token}/boom', static fn() => throw new RuntimeException('boom'));

    get('/build/r/Sup3rSecretTokenValue1234567890/boom')->assertServerError();

    $errors = sentToPulse('/server-errors');
    expect($errors)
        ->toHaveCount(1)
        ->and($errors[0]['path'])
        ->toBe('/build/r/{token}/boom')
        ->and($errors[0]['message'])
        ->toBe('boom');
});

it('records each API call once, with the route template', function (): void {
    switchPulseOn();

    get('/api/v1/sign/Sup3rSecretTokenValue1234567890');
    postJson('/api/v1/billing/webhook', []);
    postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong-password']);
    app()->terminate();

    $inbound = array_values(array_filter(auditRows(), static fn(array $row): bool => $row['direction'] === 'in'));

    expect(array_column($inbound, 'route'))
        ->toEqualCanonicalizing([
            '/api/v1/sign/{token}',
            '/api/v1/billing/webhook',
            '/api/v1/auth/login',
        ])
        ->and(array_column($inbound, 'kind', 'route')['/api/v1/billing/webhook'])
        ->toBe('webhook')
        ->and(json_encode($inbound))
        ->not
        ->toContain('Sup3rSecret')
        ->and(app(ApiCallRecorder::class)->isEnabled())
        ->toBeTrue();
});

it('records a sign-in, a failure and a sign-out here and in Pulse', function (): void {
    switchPulseOn();
    ['user' => $user] = tenantUser();
    $headers = ['Referer' => config('app.url')];

    postJson(
        '/api/v1/auth/login',
        ['email' => $user->email, 'password' => 'wrong-password'],
        $headers,
    )->assertUnprocessable();
    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], $headers)->assertOk();
    postJson('/api/v1/auth/logout', [], $headers)->assertOk();

    expect(AuthEvent::query()->orderBy('id')->pluck('event')->all())->toBe(['login_failed', 'login_ok', 'logout']);

    $sent = sentToPulse('/api/auth-events');
    expect(array_column($sent, 'event'))
        ->toBe(['login_failed', 'login_ok', 'logout'])
        ->and($sent[1]['user_ref'])
        ->toBe((string) $user->id)
        ->and(array_keys($sent[1]))
        ->toEqualCanonicalizing(['event', 'occurred_at', 'product', 'user_ref', 'ip', 'user_agent', 'outcome'])
        ->and(json_encode($sent))
        ->not->toContain($user->email);

    Http::assertSent(
        static fn(OutboundRequest $request): bool => $request->url() === 'https://pulse.test/api/auth-events'
        && $request->hasHeader('Authorization', 'Bearer test-write-key'),
    );
});

it('does not call a two-factor password step a sign-in, and records the code step', function (): void {
    ['user' => $user] = tenantUser();
    $user->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $headers = ['Referer' => config('app.url')];

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'], $headers)->assertAccepted();
    expect(AuthEvent::query()->count())->toBe(0);

    postJson('/api/v1/auth/two-factor/challenge', ['code' => 'not-the-code'], $headers)->assertUnprocessable();
    postJson('/api/v1/auth/two-factor/challenge', ['code' => 'recovery-one'], $headers)->assertOk();

    expect(AuthEvent::query()->orderBy('id')->pluck('event')->all())->toBe(['mfa_fail', 'login_ok', 'mfa_pass']);
});

it('records a sign-up', function (): void {
    postJson(
        '/api/v1/auth/register',
        [
            'name' => 'New Person',
            'email' => 'new-person@example.test',
            'password' => 'a-long-Passw0rd!',
            'accept_terms' => true,
        ],
        ['Referer' => config('app.url')],
    )->assertCreated();

    expect(AuthEvent::query()->pluck('event')->all())->toContain('sign_up');
});

it('keeps auth events for 90 days', function (): void {
    foreach ([91, 89] as $days) {
        AuthEvent::query()->create(['event' => 'logout', 'outcome' => 'ok', 'occurred_at' => now()->subDays($days)]);
    }

    artisan('model:prune', ['--model' => [AuthEvent::class]])->assertSuccessful();

    expect(AuthEvent::query()->count())->toBe(1);
});

it('sends an app event to Pulse, and nothing without a key', function (): void {
    Http::fake();
    app(PulseAdapterInterface::class)->track('inspection_submitted', ['inspection_id' => 7]);
    Http::assertNothingSent();

    switchPulseOn();
    ['user' => $user] = tenantUser();
    actingAs($user);

    app(PulseAdapterInterface::class)->track('inspection_submitted', ['inspection_id' => 7]);

    $sent = sentToPulse('/api/environments/test-write-key/events');
    expect($sent)
        ->toHaveCount(1)
        ->and($sent[0]['name'])
        ->toBe('inspection_submitted')
        ->and($sent[0]['properties'])
        ->toBe(['inspection_id' => 7])
        ->and($sent[0]['external_user_id'])
        ->toBe((string) $user->id);
});
