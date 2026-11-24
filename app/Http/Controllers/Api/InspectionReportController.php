<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\InspectionRepository;
use App\Services\ReportLinkService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

final class InspectionReportController extends Controller
{
    public function __construct(
        private readonly InspectionRepository $inspections,
        private readonly ReportService $reports,
        private readonly ReportLinkService $links,
    ) {}

    public function show(int $inspection): JsonResponse
    {
        $report = $this->locate($inspection);

        if ($report === null) {
            return $this->notLocked();
        }

        return response()->json(['data' => $report->payload()]);
    }

    public function download(int $inspection): Response|JsonResponse
    {
        $report = $this->locate($inspection);
        $bytes = $report === null ? null : $this->reports->bytes($report);

        if ($report === null || $bytes === null) {
            return $this->notLocked();
        }

        $model = $this->inspections->find($inspection);

        if ($model !== null) {
            $this->reports->recordDownload($model, $report, Auth::id() === null ? null : (int) Auth::id());
        }

        return response($bytes, HttpResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $report->reference . '.pdf',
                'report-' . $report->id . '.pdf',
            ),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function issueLink(int $inspection): JsonResponse
    {
        $report = $this->locate($inspection);
        $model = $this->inspections->find($inspection);

        if ($report === null || $model === null) {
            return $this->notLocked();
        }

        $link = $this->links->issue($model, $report, Auth::id() === null ? null : (int) Auth::id());

        return response()->json([
            'data' => [
                'url' => url('/r/' . $link['token']),
                'expires_at' => $link['expires_at']->toIso8601String(),
            ],
        ], HttpResponse::HTTP_CREATED);
    }

    public function revokeLinks(int $inspection): JsonResponse
    {
        $report = $this->locate($inspection);

        if ($report === null) {
            return $this->notLocked();
        }

        return response()->json(['data' => ['revoked' => $this->links->revokeAll($report)]]);
    }

    /** Also retries the lock for a fully signed inspection whose first attempt failed. */
    private function locate(int $inspection): ?\App\Models\InspectionReport
    {
        $model = $this->inspections->find($inspection);

        if ($model === null) {
            return null;
        }

        return $this->reports->findFor($model) ?? $this->reports->lockWhenFullySigned($model);
    }

    private function notLocked(): JsonResponse
    {
        return response()->json([
            'message' => 'No locked report exists for this inspection yet.',
        ], HttpResponse::HTTP_NOT_FOUND);
    }
}
