<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\OrganisationWriteFactory;
use App\Factories\UserWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganisationRequest;
use App\Http\Requests\SwitchOrganisationRequest;
use App\Http\Requests\UpdateOrganisationRequest;
use App\Repositories\OrganisationRepository;
use App\Repositories\UserRepository;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrganisationController extends Controller
{
    public function __construct(
        private readonly OrganisationRepository $organisations,
        private readonly OrganisationWriteFactory $factory,
        private readonly UserRepository $userRepository,
        private readonly UserWriteFactory $userWriteFactory,
        private readonly CurrentOrganisation $currentOrganisation,
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

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return response()->json(['data' => $this->factory->update($model, $data)]);
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
}
