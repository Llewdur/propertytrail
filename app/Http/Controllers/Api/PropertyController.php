<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\PropertyWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePropertyRequest;
use App\Http\Requests\UpdatePropertyRequest;
use App\Models\Property;
use App\Models\Site;
use App\Models\User;
use App\Repositories\PropertyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PropertyController extends Controller
{
    public function __construct(
        private readonly PropertyRepository $properties,
        private readonly PropertyWriteFactory $factory,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Property::class);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->properties->allForUser($user)]);
    }

    public function store(StorePropertyRequest $request): JsonResponse
    {
        $this->authorize('create', Property::class);

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

        $this->authorize('view', $model);

        return response()->json(['data' => $model]);
    }

    public function update(UpdatePropertyRequest $request, int $property): JsonResponse
    {
        $model = $this->properties->find($property);

        if ($model === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->authorize('update', $model);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // Moving a unit changes who can see a site's areas.
        if (array_key_exists('site_id', $data) && (int) $data['site_id'] !== (int) $model->site_id) {
            $this->authorize('manage', Site::class);
        }

        $this->factory->update($model, $data);

        return response()->json(['data' => $model->load('rooms')]);
    }

    public function destroy(int $property): JsonResponse
    {
        $model = $this->properties->find($property);

        if ($model === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->authorize('delete', $model);
        $this->factory->delete($model);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
