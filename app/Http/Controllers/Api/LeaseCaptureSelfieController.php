<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptureSelfie;
use App\Repositories\LeaseRepository;
use App\Services\CaptureSelfieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Staff side of the tenant's capture selfies for one lease. Every view of the image is audited. */
final class LeaseCaptureSelfieController extends Controller
{
    public function __construct(
        private readonly LeaseRepository $leases,
        private readonly CaptureSelfieService $selfies,
    ) {}

    public function index(int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound('Lease not found.');
        }

        return response()->json(
            [
                'data' => $this->selfies
                    ->forLease($model)
                    ->map(static fn(CaptureSelfie $selfie): array => [
                        'id' => $selfie->id,
                        'phase' => $selfie->phase,
                        'taken_at' => $selfie->taken_at->toIso8601String(),
                        'sha256_hash' => $selfie->sha256_hash,
                    ])
                    ->values()
                    ->all(),
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    public function show(int $lease, int $selfie): Response
    {
        $model = $this->leases->find($lease);
        $id = Auth::id();
        $opened = $model === null ? null : $this->selfies->open($model, $selfie, $id === null ? null : (int) $id);

        if ($opened === null) {
            return $this->notFound('Selfie not found.');
        }

        return response($opened['contents'], Response::HTTP_OK, [
            'Content-Type' => $opened['selfie']->mime_type,
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_NOT_FOUND);
    }
}
