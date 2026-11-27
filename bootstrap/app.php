<?php

declare(strict_types=1);

use App\Exceptions\PlanLimitExceeded;
use App\Http\Middleware\EnsurePropertyAccess;
use App\Http\Middleware\ForbidProxyTransform;
use App\Http\Middleware\RequireResolvedOrganisationForWrites;
use App\Http\Middleware\ResolveCurrentOrganisation;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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

        // The capture routes authenticate with a token header and use no cookie, so there is nothing to forge.
        $middleware->preventRequestForgery(except: ['api/v1/capture/*']);

        $middleware->alias([
            'property.access' => EnsurePropertyAccess::class,
        ]);

        $middleware->web(append: [
            ResolveCurrentOrganisation::class,
            ForbidProxyTransform::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn(Request $request) => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (PlanLimitExceeded $exception, Request $request): ?JsonResponse {
            if (!$request->is('api/*') && !$request->expectsJson()) {
                return null;
            }

            return response()->json([
                'code' => 'plan_limit',
                'message' => $exception->getMessage(),
                'upgrade' => $exception->upgrade(),
            ], HttpResponse::HTTP_PAYMENT_REQUIRED);
        });
    })
    ->create();
