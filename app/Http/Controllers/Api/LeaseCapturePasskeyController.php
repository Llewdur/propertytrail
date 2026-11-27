<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CaptureLinkRequest;
use App\Repositories\LeaseRepository;
use App\Services\LeaseCapturePasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Staff side of the tenant's capture passkeys: see which phases have one, reset a lost one. */
final class LeaseCapturePasskeyController extends Controller
{
    public function __construct(
        private readonly LeaseRepository $leases,
        private readonly LeaseCapturePasskeyService $passkeys,
    ) {}

    public function index(int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound();
        }

        return response()->json(['data' => $this->passkeys->forLease($model)]);
    }

    public function destroy(CaptureLinkRequest $request, int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound();
        }

        $id = Auth::id();
        $reset = $this->passkeys->reset($model, (string) $request->validated('phase'), $id === null ? null : (int) $id);

        return response()->json(['data' => ['reset' => $reset]]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Lease not found.'], Response::HTTP_NOT_FOUND);
    }
}
