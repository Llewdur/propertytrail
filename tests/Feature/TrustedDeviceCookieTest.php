<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;

it('leaves the configured trusted device cookie unencrypted', function (): void {
    $middleware = app(EncryptCookies::class);

    expect($middleware->isDisabled((string) config('auth-package.trusted_devices.cookie')))->toBeTrue();
});
