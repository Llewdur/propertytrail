<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\AreaRoomWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAreaRoomRequest;
use App\Http\Requests\UpdateAreaRoomRequest;
use App\Repositories\AreaRoomRepository;
use App\Repositories\PropertyRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AreaRoomController extends Controller
{
    public function __construct(
        private readonly AreaRoomRepository $rooms,
        private readonly PropertyRepository $properties,
        private readonly AreaRoomWriteFactory $factory,
    ) {}

    public function index(int $property): JsonResponse
    {
        if ($this->properties->find($property) === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $this->rooms->allForProperty($property)]);
    }

    public function store(StoreAreaRoomRequest $request, int $property): JsonResponse
    {
        if ($this->properties->find($property) === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $room = $this->factory->create(
            $data
            + [
                'property_id' => $property,
                'sort_order' => $data['sort_order'] ?? 0,
            ],
        );

        return response()->json(['data' => $room], Response::HTTP_CREATED);
    }

    public function update(UpdateAreaRoomRequest $request, int $room): JsonResponse
    {
        $model = $this->rooms->find($room);

        if ($model === null) {
            return response()->json(['message' => 'Room not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        return response()->json(['data' => $this->factory->update($model, $data)]);
    }

    public function destroy(int $room): JsonResponse
    {
        $model = $this->rooms->find($room);

        if ($model === null) {
            return response()->json(['message' => 'Room not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->factory->delete($model);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
