<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SigningLinkRequest;
use App\Http\Requests\StoreSignatureRequest;
use App\Repositories\InspectionRepository;
use App\Services\SigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class InspectionSignatureController extends Controller
{
    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly SigningService $signing,
    ) {}

    public function store(StoreSignatureRequest $request, int $inspection): JsonResponse
    {
        $model = $this->inspections->find($inspection);

        if ($model === null) {
            return response()->json(['message' => 'Inspection not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->signing->signInApp(
                $model,
                (string) $request->validated('party'),
                (string) $request->validated('signer_name'),
                (string) $request->validated('signature'),
                (int) Auth::id(),
                $request->ip(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => $this->inspections->find($inspection)?->payload()], Response::HTTP_CREATED);
    }

    public function issueLink(SigningLinkRequest $request, int $inspection): JsonResponse
    {
        $model = $this->inspections->find($inspection);

        if ($model === null) {
            return response()->json(['message' => 'Inspection not found.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $link = $this->signing->issueLink($model, (string) $request->validated('party'));
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => [
                'url' => url('/sign/' . $link['token']),
                'expires_at' => $link['expires_at']->toIso8601String(),
            ],
        ], Response::HTTP_CREATED);
    }

    public function revokeLinks(SigningLinkRequest $request, int $inspection): JsonResponse
    {
        $model = $this->inspections->find($inspection);

        if ($model === null) {
            return response()->json(['message' => 'Inspection not found.'], Response::HTTP_NOT_FOUND);
        }

        $revoked = $this->signing->revokeLinks($model, (string) $request->validated('party'));

        return response()->json(['data' => ['revoked' => $revoked]]);
    }
}
