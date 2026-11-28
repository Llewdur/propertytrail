<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AnswersCaptureRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\SharedPhotoResponseRequest;
use App\Models\CaptureLink;
use App\Services\CaptureLinkService;
use App\Services\SharedConditionPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The tenant's view of the earlier condition photos staff shared with their tenancy. */
final class PublicCaptureSharedPhotoController extends Controller
{
    use AnswersCaptureRequests;

    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly SharedConditionPhotoService $shared,
    ) {}

    public function photo(Request $request, int $shared): Response
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $photo = $this->shared->findLive($link->lease_id, $shared);
        $contents = $photo === null ? null : $this->shared->contents($photo);

        if ($photo === null || $contents === null) {
            return response()->json(['message' => 'Photo not found.'], Response::HTTP_NOT_FOUND);
        }

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => $photo->mime_type,
            'Content-Disposition' => 'attachment',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function respond(SharedPhotoResponseRequest $request, int $shared): JsonResponse
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $photo = $this->shared->findLive($link->lease_id, $shared);

        if ($photo === null) {
            return response()->json(['message' => 'Photo not found.'], Response::HTTP_NOT_FOUND);
        }

        $note = $request->string('note')->trim()->toString();
        $this->shared->respond($photo, $request->string('response')->toString(), $note === '' ? null : $note);

        return $this->private(['data' => $this->shared->forTenant($link->lease_id)]);
    }
}
