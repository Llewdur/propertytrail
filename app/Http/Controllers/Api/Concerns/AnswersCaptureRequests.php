<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\CaptureLink;
use App\Services\CaptureLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared by the account-less capture endpoints. The token travels in a header,
 * never in the path or query.
 */
trait AnswersCaptureRequests
{
    /** The live link, or the refusal to send back. */
    private function link(Request $request, CaptureLinkService $links): CaptureLink|JsonResponse
    {
        $link = $links->resolve((string) $request->header('X-Capture-Token', ''));

        if ($link === null) {
            return response()->json(['message' => 'This link is not valid.'], Response::HTTP_NOT_FOUND);
        }

        if (!$link->isLive()) {
            return response()->json([
                'message' => 'This link has expired or was withdrawn. Ask for a new one; your photos are saved.',
            ], Response::HTTP_GONE);
        }

        return $link;
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @param array<string, mixed> $body */
    private function private(array $body, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store, private']);
    }
}
