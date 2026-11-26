<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells Cloudflare to serve web pages unchanged. Without it, Cloudflare adds its analytics script to the
 * HTML, which the app does not want on signed-in pages and which its Content-Security-Policy blocks.
 */
final class ForbidProxyTransform
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->addCacheControlDirective('no-transform');

        return $response;
    }
}
