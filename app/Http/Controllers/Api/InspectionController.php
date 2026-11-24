<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\InspectionWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveInspectionSectionRequest;
use App\Http\Requests\StoreInspectionRequest;
use App\Models\Inspection;
use App\Repositories\InspectionRepository;
use App\Repositories\LeaseRepository;
use App\Repositories\PropertyRepository;
use App\Services\InspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class InspectionController extends Controller
{
    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly PropertyRepository $properties,
        private readonly LeaseRepository $leases,
        private readonly InspectionWriteFactory $factory,
        private readonly InspectionService $engine,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->inspections->all()->map(
                static fn(Inspection $inspection): array => $inspection->payload(),
            )->all(),
        ]);
    }

    public function store(StoreInspectionRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $property = $this->properties->find((int) $data['property_id']);

        if ($property === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        $lease = null;

        if (array_key_exists('lease_id', $data) && $data['lease_id'] !== null) {
            $lease = $this->leases->find((int) $data['lease_id']);

            if ($lease === null || (int) $lease->property_id !== (int) $property->id) {
                return response()->json([
                    'message' => 'Lease does not belong to this property.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $inspection = $this->factory->create(
            $property,
            (string) $data['phase'],
            $lease,
            is_string($data['overall_notes'] ?? null) ? $data['overall_notes'] : null,
        );

        return response()->json(['data' => $inspection->payload()], Response::HTTP_CREATED);
    }

    public function show(int $inspection): JsonResponse
    {
        $model = $this->inspections->find($inspection);

        if ($model === null) {
            return response()->json(['message' => 'Inspection not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $model->payload()]);
    }

    public function updateSection(SaveInspectionSectionRequest $request, int $inspection, int $section): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $model = $this->inspections->find($inspection);

        if ($model === null) {
            return response()->json(['message' => 'Inspection not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$model->isDraft()) {
            return response()->json([
                'message' => 'A submitted inspection can no longer be changed. Start a new inspection instead.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $target = $model->sections->firstWhere('id', $section);

        if ($target === null) {
            return response()->json(['message' => 'Section not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $data['items'];
            $this->engine->saveSection($model, $target, $rows);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => $this->inspections->find($inspection)?->payload()]);
    }

    public function submit(int $inspection): JsonResponse
    {
        $model = $this->inspections->find($inspection);

        if ($model === null) {
            return response()->json(['message' => 'Inspection not found.'], Response::HTTP_NOT_FOUND);
        }

        $errors = $this->engine->submit($model, (int) Auth::id());

        if ($errors !== []) {
            return response()->json([
                'message' => 'Inspection is not ready to submit.',
                'errors' => $errors,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => $this->inspections->find($inspection)?->payload()]);
    }
}
