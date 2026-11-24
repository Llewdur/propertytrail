<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\LeaseWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreLeaseRequest;
use App\Repositories\LeaseRepository;
use App\Repositories\PropertyRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class LeaseController extends Controller
{
    public function __construct(
        private readonly LeaseRepository $leases,
        private readonly LeaseWriteFactory $factory,
        private readonly PropertyRepository $properties,
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
        $lease = $this->factory->create(
            $data
            + [
                'property_id' => $property,
                'status' => $data['status'] ?? 'active',
            ],
        );

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
