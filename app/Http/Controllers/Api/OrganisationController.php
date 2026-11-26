<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\OrganisationWriteFactory;
use App\Factories\UserWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInvitationRequest;
use App\Http\Requests\StoreOrganisationRequest;
use App\Http\Requests\SwitchOrganisationRequest;
use App\Http\Requests\UpdateOrganisationRequest;
use App\Http\Requests\UploadOrganisationLogoRequest;
use App\Models\Organisation;
use App\Models\OrganisationInvitation;
use App\Models\User;
use App\Repositories\OrganisationRepository;
use App\Repositories\UserRepository;
use App\Services\ReportBrandingService;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class OrganisationController extends Controller
{
    public function __construct(
        private readonly OrganisationRepository $organisations,
        private readonly OrganisationWriteFactory $factory,
        private readonly UserRepository $userRepository,
        private readonly UserWriteFactory $userWriteFactory,
        private readonly CurrentOrganisation $currentOrganisation,
        private readonly ReportBrandingService $branding,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->organisations->allForUser($user->getKey()),
            'current_organisation_id' => $this->currentOrganisation->id(),
        ]);
    }

    public function store(StoreOrganisationRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $organisation = $this->factory->create($data);
        $this->userWriteFactory->attachToOrganisation($request->user(), $organisation, 'admin');

        return response()->json(['data' => $organisation], Response::HTTP_CREATED);
    }

    public function update(UpdateOrganisationRequest $request, int $organisation): JsonResponse
    {
        $model = $this->organisations->find($organisation);

        if ($model === null || !$this->userRepository->belongsToOrganisation($request->user(), $organisation)) {
            return response()->json(['message' => 'Organisation not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->authorize('manageTeam', $model);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return response()->json(['data' => $this->factory->update($model, $data)]);
    }

    public function uploadLogo(UploadOrganisationLogoRequest $request, int $organisation): JsonResponse
    {
        $model = $this->organisations->find($organisation);

        if ($model === null || !$this->userRepository->belongsToOrganisation($request->user(), $organisation)) {
            return response()->json(['message' => 'Organisation not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->authorize('manageTeam', $model);

        $this->branding->storeLogo($model, $request->file('logo'));

        return response()->json(['data' => $model->refresh()]);
    }

    public function members(int $organisation): JsonResponse
    {
        $model = $this->resolvedOrganisation($organisation);
        $this->authorize('manageTeam', $model);

        $members = $model
            ->users()
            ->get()
            ->map(static function (User $member): array {
                /** @var \Illuminate\Database\Eloquent\Relations\Pivot|null $pivot */
                $pivot = $member->getAttribute('pivot');

                return [
                    'id' => $member->getKey(),
                    'name' => $member->getAttribute('name'),
                    'email' => $member->getAttribute('email'),
                    'role' => $pivot?->getAttribute('role'),
                ];
            })
            ->all();

        return response()->json(['data' => $members]);
    }

    public function invitations(StoreInvitationRequest $request, int $organisation): JsonResponse
    {
        $model = $this->resolvedOrganisation($organisation);
        $this->authorize('manageTeam', $model);

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        /** @var User $user */
        $user = $request->user();
        $token = Str::random(64);

        $invitation = OrganisationInvitation::query()->create([
            'organisation_id' => $model->getKey(),
            'email' => $data['email'],
            'role' => $data['role'],
            'token_hash' => hash('sha256', $token),
            'invited_by' => $user->getKey(),
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(['data' => $invitation, 'token' => $token], Response::HTTP_CREATED);
    }

    public function switch(SwitchOrganisationRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $organisationId = (int) $data['organisation_id'];

        /** @var \App\Models\User $user */
        $user = $request->user();

        if (!$this->userRepository->belongsToOrganisation($user, $organisationId)) {
            return response()->json(['message' => 'Organisation not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->currentOrganisation->set($organisationId);
        $request->session()->put('current_organisation_id', $organisationId);

        return response()->json(['data' => $this->organisations->find($organisationId)]);
    }

    private function resolvedOrganisation(int $organisation): Organisation
    {
        $model = $this->organisations->find($organisation);

        if ($model === null || $model->getKey() !== $this->currentOrganisation->id()) {
            abort(Response::HTTP_NOT_FOUND, 'Organisation not found.');
        }

        return $model;
    }
}
