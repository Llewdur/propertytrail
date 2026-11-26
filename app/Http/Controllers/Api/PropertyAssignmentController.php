<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePropertyAssignmentRequest;
use App\Models\Organisation;
use App\Models\Property;
use App\Models\PropertyAssignment;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PropertyAssignmentController extends Controller
{
    public function store(StorePropertyAssignmentRequest $request, Property $property): JsonResponse
    {
        /** @var Organisation $organisation */
        $organisation = $property->organisation()->firstOrFail();
        $this->authorize('manageTeam', $organisation);

        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $assignment = PropertyAssignment::query()->updateOrCreate([
            'organisation_id' => $property->organisation_id,
            'property_id' => $property->getKey(),
            'user_id' => (int) $data['user_id'],
        ], ['role' => $data['role']]);

        return response()->json(
            ['data' => $assignment],
            $assignment->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function destroy(Property $property, int $user): JsonResponse
    {
        /** @var Organisation $organisation */
        $organisation = $property->organisation()->firstOrFail();
        $this->authorize('manageTeam', $organisation);

        /** @var PropertyAssignment $assignment */
        $assignment = $property->assignments()->where('user_id', $user)->firstOrFail();
        $assignment->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
