<?php

declare(strict_types=1);

use function Pest\Laravel\get;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn() => resetTenant());

it('reports health with all services', function (): void {
    get('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('services.database', 'ok')
        ->assertJsonPath('services.redis', 'ok')
        ->assertJsonPath('services.storage', 'ok');
});

it('reports the application version', function (): void {
    get('/version')->assertOk()->assertJsonPath('version', config('app.version'));
});

it('serves the SPA shell with bootstrap config', function (): void {
    get('/app')->assertOk()->assertSee('window.__PROPERTYTRAIL_BOOTSTRAP__', false)->assertSee('/spa/assets/', false);
});
