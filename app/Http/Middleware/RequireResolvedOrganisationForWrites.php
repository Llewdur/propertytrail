<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganisation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Refuses state-changing requests that have no tenant resolved. */
final class RequireResolvedOrganisationForWrites
{
    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (
            !in_array($request->getRealMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            && !$request->is('api/v1/auth/*')
            && !$request->is('api/v1/sign/*')
            && !$request->is('api/v1/billing/webhook')
            && $this->currentOrganisation->id() === null
        ) {
            return response()->json([
                'message' => 'No organisation resolved for this request.',
                'code' => 'TENANT_REQUIRED',
            ], Response::HTTP_FORBIDDEN);
        }

        /** @var Response */
        return $next($request);
    }
}
