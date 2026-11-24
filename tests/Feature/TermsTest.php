<?php

declare(strict_types=1);

use App\Models\Organisation;
use App\Models\User;
use App\Support\Legal;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn() => resetTenant());

it('publishes the current terms from the repository document', function (): void {
    $terms = (string) file_get_contents(base_path(Legal::TERMS_FILE));

    expect($terms)->toContain(Legal::TERMS_VERSION)->and(config('app.terms_version'))->toBe(Legal::TERMS_VERSION);

    get('/terms')
        ->assertOk()
        ->assertSee('PropertyTrail Terms of Service')
        ->assertSee(Legal::TERMS_VERSION)
        ->assertHeader('Content-Type', 'text/html; charset=utf-8');
});

it('registers a user without an organisation', function (): void {
    postJson('/api/v1/auth/register', [
        'name' => 'Orgless User',
        'email' => 'orgless@example.test',
        'password' => 'password',
        'accept_terms' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Orgless User');

    $user = User::query()->where('email', 'orgless@example.test')->firstOrFail();

    expect($user->organisations)
        ->toHaveCount(0)
        ->and(Organisation::query()->where('name', 'Orgless User Organisation')->exists())
        ->toBeFalse();
});
