<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Property;
use App\Models\User;
use App\Services\PropertyResolver;
use App\Support\Tenancy\CurrentOrganisation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies the property assignment rules to routes that name a property, or a
 * record belonging to one. A record that does not exist in this organisation
 * falls through so the controller answers 404.
 */
final class EnsurePropertyAccess
{
    public function __construct(
        private readonly PropertyResolver $records,
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        /** @var array<string, mixed> $parameters */
        $parameters = $request->route()?->parameters() ?? [];
        $propertyIds = $this->records->propertyIdsForRoute($parameters);

        if ($propertyIds !== null) {
            /** @var User $user */
            $user = $request->user();

            abort_unless(
                $user->isAdminIn($this->currentOrganisation->id() ?? 0)
                || Property::query()
                    ->whereKey($propertyIds)
                    ->get()
                    ->contains(
                        static fn(Property $property): bool => Gate::forUser($user)->allows($ability, $property),
                    ),
                Response::HTTP_FORBIDDEN,
                'You are not assigned to this property.',
            );
        }

        /** @var Response */
        return $next($request);
    }
}
