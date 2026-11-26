<?php

declare(strict_types=1);

use function Pest\Laravel\get;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Sanctum treats an API request as signed in only when it carries a Referer or Origin. A browser sends
 * no Origin on a same-origin GET, so the app page must let it send the Referer.
 */
it('lets the web app send its referrer to its own API', function (): void {
    get('/app')->assertOk()->assertHeader('Referrer-Policy', 'same-origin');
});
