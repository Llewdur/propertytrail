<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SignViaLinkRequest;
use App\Models\Inspection;
use App\Services\SigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/** The account-less signing surface, reached only by a hashed, expiring, single-use token. */
final class PublicSigningController extends Controller
{
    public function __construct(
        private readonly SigningService $signing,
    ) {}

    public function show(Request $request, #[\SensitiveParameter] string $token): JsonResponse
    {
        $resolved = $this->signing->resolveLink($token, $request->ip());

        if ($resolved['token'] === null) {
            return $this->refusal($resolved['outcome']);
        }

        $inspection = Inspection::query()->with('property:id,name')->findOrFail($resolved['token']->inspection_id);

        return response()->json([
            'data' => [
                'party' => $resolved['token']->party,
                'phase' => $inspection->phase,
                'property_name' => $inspection->property->name,
                'inspected_at' => $inspection->submitted_at?->toIso8601String(),
                'expires_at' => $resolved['token']->expires_at->toIso8601String(),
            ],
        ]);
    }

    public function sign(SignViaLinkRequest $request, #[\SensitiveParameter] string $token): JsonResponse
    {
        $resolved = $this->signing->resolveLink($token, $request->ip());

        if ($resolved['token'] === null) {
            return $this->refusal($resolved['outcome']);
        }

        try {
            $signature = $this->signing->signByLink(
                $resolved['token'],
                (string) $request->validated('signer_name'),
                (string) $request->validated('signature'),
                $request->ip(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'data' => ['party' => $signature->party, 'signed_at' => $signature->signed_at->toIso8601String()],
        ], Response::HTTP_CREATED);
    }

    private function refusal(string $outcome): JsonResponse
    {
        if ($outcome === SigningService::OUTCOME_UNKNOWN) {
            return response()->json(['message' => 'This signing link is not valid.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'message' => 'This signing link has expired or is no longer valid.',
        ], Response::HTTP_GONE);
    }
}
