<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganisation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active organisation from the authenticated user's membership,
 * never from an untrusted request parameter. A caller may nominate one of its
 * own organisations with X-Organisation-Id; the session carries the choice
 * between requests; a single-organisation user is resolved automatically.
 */
final class ResolveCurrentOrganisation
{
    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user !== null) {
            $organisationId =
                $this->resolveFromRequest($request, $user) ?? $this->resolveFromSession(
                    $request,
                    $user,
                ) ?? $this->resolveSingleMembership($user);

            if ($organisationId !== null) {
                $this->currentOrganisation->set($organisationId);
                $request->session()->put('current_organisation_id', $organisationId);
            }
        }

        /** @var Response */
        return $next($request);
    }

    private function resolveFromRequest(Request $request, User $user): ?int
    {
        $header = $request->header('X-Organisation-Id');

        if (!is_string($header) || $header === '') {
            return null;
        }

        $organisationId = (int) $header;

        return $user->organisationIds()->contains($organisationId) ? $organisationId : null;
    }

    private function resolveFromSession(Request $request, User $user): ?int
    {
        $organisationId = $request->session()->get('current_organisation_id');

        if (!is_numeric($organisationId)) {
            return null;
        }

        $organisationId = (int) $organisationId;

        return $user->organisationIds()->contains($organisationId) ? $organisationId : null;
    }

    private function resolveSingleMembership(User $user): ?int
    {
        $organisationIds = $user->organisationIds();

        return $organisationIds->count() === 1 ? $organisationIds->first() : null;
    }
}
