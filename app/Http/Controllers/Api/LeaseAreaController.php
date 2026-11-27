<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Factories\LeaseWriteFactory;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveLeaseAreasRequest;
use App\Models\Lease;
use App\Repositories\AreaRoomRepository;
use App\Repositories\LeaseRepository;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Which areas "the property" covers for one tenant. */
final class LeaseAreaController extends Controller
{
    public function __construct(
        private readonly LeaseRepository $leases,
        private readonly AreaRoomRepository $areas,
        private readonly LeaseWriteFactory $factory,
    ) {}

    public function show(int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return response()->json(['message' => 'Lease not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->coverage($model);
    }

    public function update(SaveLeaseAreasRequest $request, int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return response()->json(['message' => 'Lease not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array{area_ids: list<int|string>} $data */
        $data = $request->validated();
        $ids = array_map(intval(...), $data['area_ids']);

        if (array_diff($ids, $this->areas->availableForLease($model)->modelKeys()) !== []) {
            return response()->json([
                'message' => 'An area does not belong to the lease unit or its site.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->factory->setCoveredAreas($model, $ids);

        return $this->coverage($model);
    }

    private function coverage(Lease $lease): JsonResponse
    {
        return response()->json([
            'data' => [
                'available' => $this->areas->availableForLease($lease),
                'covered_ids' => $this->areas->coveredByLease($lease)->modelKeys(),
                'is_default' => $this->areas->listedForLease($lease)->isEmpty(),
            ],
        ]);
    }
}
