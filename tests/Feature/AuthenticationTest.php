<?php

declare(strict_types=1);

use App\Factories\UserWriteFactory;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Zekini\Auth\Models\TermsAcceptance;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn() => resetTenant());

it('registers a user with a new organisation and terms acceptance', function (): void {
    $response = postJson('/api/v1/auth/register', [
        'name' => 'New User',
        'email' => 'new-user@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
        'organisation_name' => 'New Organisation',
        'terms_version' => config('app.terms_version'),
        'accept_terms' => true,
    ]);

    $response->assertCreated()->assertJsonPath('data.name', 'New User');

    $user = User::query()->where('email', 'new-user@example.test')->firstOrFail();
    expect(Hash::check('password', $user->password))
        ->toBeTrue()
        ->and($user->organisations)
        ->toHaveCount(1)
        ->and($user->organisations()->first()->name)
        ->toBe('New Organisation')
        ->and(TermsAcceptance::query()->where('user_id', $user->id)->where('source', 'register')->exists())
        ->toBeTrue()
        ->and($user->terms_version)
        ->toBe(config('app.terms_version'))
        ->and($user->terms_accepted_at)
        ->not->toBeNull();
    expect(Organisation::query()->where('name', 'New Organisation')->exists())->toBeTrue();
});

it('logs in a user who accepted the current terms', function (): void {
    ['user' => $user] = tenantUser('login');

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);
});

it('does not block login for a user who has not accepted the current terms', function (): void {
    $user = app(UserWriteFactory::class)->create([
        'name' => 'No Terms User',
        'email' => 'no-terms@example.test',
        'password' => 'password',
    ]);

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', $user->email);

    expect(TermsAcceptance::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('reports terms status through the package endpoint', function (): void {
    ['user' => $user] = tenantUser('terms');

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    getJson('/api/v1/auth/terms')
        ->assertOk()
        ->assertJsonPath('data.version', config('app.terms_version'))
        ->assertJsonPath('data.accepted', true);
});

it('requires a two factor code when two factor authentication is enabled', function (): void {
    ['user' => $user] = tenantUser('twofactor');
    $user->forceFill([
        'two_factor_secret' => 'secret',
        'two_factor_recovery_codes' => '[]',
        'two_factor_confirmed_at' => now(),
    ])->save();

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertStatus(202)
        ->assertJsonPath('code', 'TWO_FACTOR_REQUIRED');
});

it('signs out through the web session route', function (): void {
    ['user' => $user] = tenantUser('web-logout');

    actingAs($user)->post('/auth/logout')->assertRedirect('/app');

    $this->assertGuest();
});

it('refuses domain writes when no organisation is resolved', function (): void {
    ['organisation' => $organisation, 'user' => $user] = tenantUser('no-tenant');
    $second = app(\App\Factories\OrganisationWriteFactory::class)->create(['name' => 'Second']);
    app(UserWriteFactory::class)->attachToOrganisation($user, $second, 'member');

    actingAs($user)
        ->postJson('/api/v1/properties', propertyPayload())
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_REQUIRED');

    expect($organisation->exists)->toBeTrue();
});
