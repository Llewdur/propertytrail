<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\LeaseWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaseRequest;
use App\Models\Lease;
use App\Repositories\LeaseRepository;
use App\Repositories\PropertyRepository;
use App\Services\TenantTurnoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class LeaseController extends Controller
{
    public function __construct(
        private readonly LeaseRepository $leases,
        private readonly LeaseWriteFactory $factory,
        private readonly PropertyRepository $properties,
        private readonly TenantTurnoverService $turnover,
    ) {}

    public function index(int $property): JsonResponse
    {
        if ($this->properties->find($property) === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $this->leases->forProperty($property)]);
    }

    public function store(StoreLeaseRequest $request, int $property): JsonResponse
    {
        if ($this->properties->find($property) === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $previous = null;

        if (($data['previous_lease_id'] ?? null) !== null) {
            $previous = $this->leases->find((int) $data['previous_lease_id']);

            if ($previous === null || (int) $previous->property_id !== $property) {
                return response()->json([
                    'message' => 'The earlier tenancy must be on this property.',
                    'errors' => ['previous_lease_id' => ['The earlier tenancy must be on this property.']],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        /** @var Lease $lease */
        $lease = DB::transaction(function () use ($data, $property, $previous): Lease {
            $lease = $this->factory->create(
                $data
                + [
                    'property_id' => $property,
                    'status' => $data['status'] ?? 'active',
                ],
            );

            if ($previous !== null) {
                $this->turnover->follow($lease, $previous);
            }

            return $lease;
        });

        return response()->json(['data' => $lease], Response::HTTP_CREATED);
    }

    public function show(int $lease): JsonResponse
    {
        $record = $this->leases->find($lease);

        if ($record === null) {
            return response()->json(['message' => 'Lease not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $record->load('property')]);
    }
}
