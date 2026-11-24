<?php

declare(strict_types=1);

use App\Http\Middleware\RequireResolvedOrganisationForWrites;
use App\Http\Middleware\ResolveCurrentOrganisation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
            StartSession::class,
            ResolveCurrentOrganisation::class,
            RequireResolvedOrganisationForWrites::class,
        ]);

        $middleware->web(append: [
            ResolveCurrentOrganisation::class,
        ]);

        $middleware->encryptCookies(except: [
            (string) env('AUTH_TRUSTED_DEVICE_COOKIE', 'auth-package_trusted_device'),
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn(Request $request) => $request->is('api/*') || $request->expectsJson());
    })
    ->create();
