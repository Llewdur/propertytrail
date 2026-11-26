<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

use function Pest\Laravel\get;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn() => resetTenant());

function fakeGoogleUser(string $email, bool $verified = true, string $id = 'google-123'): void
{
    $googleUser = new GoogleUser()->setRaw(['email_verified' => $verified])->map([
        'id' => $id,
        'name' => 'Google Person',
        'email' => $email,
    ]);

    Socialite::shouldReceive('driver->user')->andReturn($googleUser);
}

it('creates an account from a verified Google email', function (): void {
    fakeGoogleUser('new-google@example.test');

    get('/auth/google/callback')->assertRedirect('/app');

    $user = User::query()->where('email', 'new-google@example.test')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->email_verified_at)->not->toBeNull();
});

it('refuses an email Google has not verified', function (): void {
    fakeGoogleUser('unverified@example.test', verified: false);

    get('/auth/google/callback')->assertRedirect('/app?auth_error=google');

    $this->assertGuest();
    expect(User::query()->where('email', 'unverified@example.test')->exists())->toBeFalse();
});

it('links a verified local account to the matching Google email', function (): void {
    ['user' => $user] = tenantUser('google-link');
    $user->forceFill(['email_verified_at' => now()])->save();
    fakeGoogleUser($user->email);

    get('/auth/google/callback')->assertRedirect('/app');

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->google_id)->toBe('google-123');
});

it('does not link a local account whose email was never verified', function (): void {
    ['user' => $user] = tenantUser('google-unverified-local');
    $user->forceFill(['email_verified_at' => null])->save();
    fakeGoogleUser($user->email);

    get('/auth/google/callback')->assertRedirect('/app?auth_error=google_link');

    $this->assertGuest();
    expect($user->refresh()->google_id)->toBeNull();
});

it('does not let Google skip two factor authentication', function (): void {
    ['user' => $user] = tenantUser('google-two-factor');
    $user->forceFill([
        'google_id' => 'google-123',
        'two_factor_secret' => 'secret',
        'two_factor_recovery_codes' => '[]',
        'two_factor_confirmed_at' => now(),
    ])->save();
    fakeGoogleUser($user->email);

    get('/auth/google/callback')->assertRedirect('/app?auth_error=google_two_factor');

    $this->assertGuest();
});
