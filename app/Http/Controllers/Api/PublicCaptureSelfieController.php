<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AnswersCaptureRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\CaptureSelfieRequest;
use App\Models\CaptureLink;
use App\Services\CaptureLinkService;
use App\Services\CaptureSelfieService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/** The tenant's front-camera selfie, sent through their capture link. It is never shown back to the link. */
final class PublicCaptureSelfieController extends Controller
{
    use AnswersCaptureRequests;

    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly CaptureSelfieService $selfies,
    ) {}

    public function store(CaptureSelfieRequest $request): JsonResponse
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $file = $request->file('file');

        if (!$file instanceof UploadedFile) {
            return $this->refuse('Take a selfie to continue.');
        }

        $selfie = $this->selfies->record($link, $file, $request->validated('taken_at'));

        if ($selfie === null) {
            return $this->refuse('The selfie limit for this capture has been reached.');
        }

        return $this->private(['data' => ['saved' => true]], Response::HTTP_CREATED);
    }
}
