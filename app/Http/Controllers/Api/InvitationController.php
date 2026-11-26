<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\UserWriteFactory;
use App\Http\Controllers\Controller;
use App\Models\OrganisationInvitation;
use App\Models\User;
use App\Repositories\OrganisationRepository;
use App\Support\Tenancy\CurrentOrganisation;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

final class InvitationController extends Controller
{
    public function __construct(
        private readonly UserWriteFactory $users,
        private readonly OrganisationRepository $organisations,
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    public function accept(Request $request, #[SensitiveParameter] string $token): JsonResponse
    {
        $invitation = OrganisationInvitation::query()->where('token_hash', hash('sha256', $token))->first();

        if ($invitation === null) {
            abort(Response::HTTP_NOT_FOUND, 'Invitation not found.');
        }

        /** @var User $user */
        $user = $request->user();
        $organisationId = (int) $invitation->getAttribute('organisation_id');

        if (!hash_equals(
            strtolower((string) $invitation->getAttribute('email')),
            strtolower((string) $user->getAttribute('email')),
        )) {
            abort(Response::HTTP_FORBIDDEN, 'This invitation was issued to another email address.');
        }

        DB::transaction(function () use ($invitation, $user, $organisationId): void {
            /** @var CarbonInterface|null $acceptedAt */
            $acceptedAt = $invitation->getAttribute('accepted_at');
            /** @var CarbonInterface|null $expiresAt */
            $expiresAt = $invitation->getAttribute('expires_at');

            if ($acceptedAt !== null || !$expiresAt instanceof CarbonInterface || $expiresAt->isPast()) {
                abort(Response::HTTP_CONFLICT, 'Invitation is no longer valid.');
            }

            $organisation = $this->organisations->find($organisationId);

            if ($organisation === null) {
                abort(Response::HTTP_NOT_FOUND, 'Organisation not found.');
            }

            // An invitation never carries the admin role, so accepting one would demote an admin.
            if ($user->isAdminIn($organisation)) {
                abort(Response::HTTP_CONFLICT, 'You are already an admin of this organisation.');
            }

            $claimed = OrganisationInvitation::query()
                ->whereKey($invitation->getKey())
                ->whereNull('accepted_at')
                ->where('expires_at', '>', now())
                ->update(['accepted_at' => now()]);

            if ($claimed !== 1) {
                abort(Response::HTTP_CONFLICT, 'Invitation is no longer valid.');
            }

            $this->users->setRole($user, $organisation, (string) $invitation->getAttribute('role'));
        });

        $this->currentOrganisation->set($organisationId);
        $request->session()->put('current_organisation_id', $organisationId);

        return response()->json(['data' => $this->organisations->find($organisationId)]);
    }
}
