<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\PropertyWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\UpdatePropertyRequest;
use App\Repositories\PropertyRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PropertyController extends Controller
{
    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly PropertyWriteFactory $factory,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->properties->all()]);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $property = $this->factory->create($data);

        return response()->json(['data' => $property->load('rooms')], Response::HTTP_CREATED);
    }

    public function show(int $property): JsonResponse
    {
        $model = $this->properties->find($property);

        if ($model === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $model]);
    }

    public function update(UpdatePropertyRequest $request, int $property): JsonResponse
    {
        $model = $this->properties->find($property);

        if ($model === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $this->factory->update($model, $data);

        return response()->json(['data' => $model->load('rooms')]);
    }

    public function destroy(int $property): JsonResponse
    {
        $model = $this->properties->find($property);

        if ($model === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->factory->delete($model);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
