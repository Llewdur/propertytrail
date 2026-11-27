<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\AnswersCaptureRequests;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CaptureLink;
use App\Repositories\AuditLogRepository;
use App\Services\CaptureLinkService;
use App\Support\AuditHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The tenant's own history on their tenancy, across every link they were sent. Staff activity is not shown. */
final class PublicCaptureHistoryController extends Controller
{
    use AnswersCaptureRequests;

    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly AuditLogRepository $auditLogs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $link = $this->unlockedLink($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        return $this->private([
            'data' => $this->auditLogs
                ->tenantActions($link->lease_id, 500)
                ->map(static function (AuditLog $log): array {
                    $entry = AuditHistory::entry($log);
                    unset($entry['actor'], $entry['actor_user_id']);

                    return $entry;
                })
                ->values()
                ->all(),
        ]);
    }
}
