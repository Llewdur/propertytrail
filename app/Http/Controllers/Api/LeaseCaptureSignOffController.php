<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CaptureSignOff;
use App\Repositories\LeaseRepository;
use App\Services\CaptureSignOffCopy;
use App\Services\CaptureSignOffService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Staff side of the tenant's sign-offs for one lease: every version, and its PDF copy. */
final class LeaseCaptureSignOffController extends Controller
{
    public function __construct(
        private readonly LeaseRepository $leases,
        private readonly CaptureSignOffService $signOffs,
        private readonly CaptureSignOffCopy $copies,
    ) {}

    public function index(int $lease): JsonResponse
    {
        $model = $this->leases->find($lease);

        if ($model === null) {
            return $this->notFound('Lease not found.');
        }

        $all = $this->signOffs->forLease($model);
        $newest = $all->groupBy('phase')->map(static fn($versions): int => (int) $versions->max('version'));

        return response()->json(
            [
                'data' => $all->map(static fn(CaptureSignOff $signOff): array => [
                    'id' => $signOff->id,
                    'phase' => $signOff->phase,
                    'version' => $signOff->version,
                    'reference' => $signOff->reference(),
                    'signer_name' => $signOff->signer_name,
                    'signed_at' => $signOff->signed_at->toIso8601String(),
                    'content_hash' => $signOff->content_hash,
                    'declaration_version' => $signOff->declaration_version,
                    'comment' => $signOff->comment,
                    'phone_unlocked' => $signOff->phone_unlocked,
                    'capture_selfie_id' => $signOff->capture_selfie_id,
                    'superseded' => $signOff->version < $newest->get($signOff->phase, 0),
                ])->values()->all(),
            ],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store, private'],
        );
    }

    public function copy(int $lease, int $signOff): Response
    {
        $model = $this->leases->find($lease);
        $held = $model === null ? null : CaptureSignOff::query()->where('lease_id', $model->getKey())->find($signOff);

        if ($held === null) {
            return $this->notFound('Sign-off not found.');
        }

        return $this->copies->download($held);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_NOT_FOUND);
    }
}
