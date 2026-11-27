<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AnswersCaptureRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\CaptureNoteRequest;
use App\Models\CaptureLink;
use App\Models\Lease;
use App\Services\CaptureLinkService;
use App\Services\CaptureNoteService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** A tenant's room rating or reported problem, sent through their capture link. */
final class PublicCaptureNoteController extends Controller
{
    use AnswersCaptureRequests;

    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly CaptureNoteService $notes,
    ) {}

    public function store(CaptureNoteRequest $request): JsonResponse
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $refusal = $this->notes->record($link, Lease::query()->findOrFail($link->lease_id), $data);

        if ($refusal !== null) {
            return $this->refuse($refusal);
        }

        return $this->private(['data' => ['saved' => true]], Response::HTTP_CREATED);
    }
}
