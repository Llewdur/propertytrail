<?php

declare(strict_types=1);

use function Pest\Laravel\get;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/** Cloudflare adds its analytics script to a page unless the response forbids changing it. */
it('stops the proxy rewriting web pages', function (string $path): void {
    $response = get($path)->assertOk();

    expect($response->headers->hasCacheControlDirective('no-transform'))->toBeTrue();
})->with(['/', '/app', '/terms']);

it('gives the sign-up form a link to the terms', function (): void {
    get('/app')->assertOk()->assertSee('"termsUrl":' . json_encode(url('/terms'), JSON_THROW_ON_ERROR), false);
});
