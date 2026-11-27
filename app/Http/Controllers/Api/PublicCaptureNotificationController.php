<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AnswersCaptureRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\CaptureReminderFallbackRequest;
use App\Models\CaptureLink;
use App\Services\CaptureLinkService;
use App\Services\CaptureNotificationService;
use Illuminate\Http\JsonResponse;

/** The tenant chooses WhatsApp reminders when the phone cannot take notifications. */
final class PublicCaptureNotificationController extends Controller
{
    use AnswersCaptureRequests;

    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly CaptureNotificationService $notifications,
    ) {}

    public function fallback(CaptureReminderFallbackRequest $request): JsonResponse
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $this->notifications->fallback($link, (string) $request->validated('phone'));

        return $this->private(['data' => $this->notifications->state($link)]);
    }
}
