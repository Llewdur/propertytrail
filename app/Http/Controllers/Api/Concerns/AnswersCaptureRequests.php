<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Concerns;

use App\Models\CaptureLink;
use App\Services\CaptureLinkService;
use App\Services\CapturePasskeyService;
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

    /** The live link, refused while its passkey has not unlocked this visit. */
    private function unlockedLink(Request $request, CaptureLinkService $links): CaptureLink|JsonResponse
    {
        $link = $this->link($request, $links);

        if ($link instanceof CaptureLink && !$this->isUnlocked($request, $link)) {
            return response()->json([
                'message' => 'Unlock with your phone to continue. Your photos are saved on it.',
                'locked' => true,
            ], Response::HTTP_LOCKED);
        }

        return $link;
    }

    private function isUnlocked(Request $request, CaptureLink $link): bool
    {
        return app(CapturePasskeyService::class)->unlocked(
            $link,
            (string) $request->headers->get(CapturePasskeyService::UNLOCK_HEADER, ''),
        );
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
