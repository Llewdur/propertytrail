<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MoveOutRequest;
use App\Repositories\LeaseRepository;
use App\Services\TenantTurnoverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Staff end a tenancy when the tenant moves out. */
final class LeaseTurnoverController extends Controller
{
    public function __construct(
        private readonly LeaseRepository $leases,
        private readonly TenantTurnoverService $turnover,
    ) {}

    public function moveOut(MoveOutRequest $request, int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return response()->json(['message' => 'Lease not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($model->hasMovedOut()) {
            return response()->json(['message' => 'This tenant has already moved out.'], Response::HTTP_CONFLICT);
        }

        $date = $request->date('end_date') ?? today();

        if ($date->lessThan($model->start_date)) {
            return response()->json([
                'message' => 'The move-out date cannot be before the lease started.',
                'errors' => ['end_date' => ['The move-out date cannot be before the lease started.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $id = Auth::id();

        return response()->json([
            'data' => $this->turnover->moveOut($model, $date, $id === null ? null : (int) $id),
        ]);
    }
}
