<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\InspectionReport;
use App\Services\ReportLinkService;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** The account-less read view of a locked report, reached only by a hashed, expiring token. */
final class PublicReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportLinkService $links,
    ) {}

    public function __invoke(Request $request, #[\SensitiveParameter] string $token): Response
    {
        $resolved = $this->links->resolve($token, $request->ip());

        if ($resolved['link'] === null) {
            $unknown = $resolved['outcome'] === ReportLinkService::OUTCOME_UNKNOWN;

            return response(
                $unknown ? 'This report link is not valid.' : 'This report link has expired or is no longer valid.',
                $unknown ? HttpResponse::HTTP_NOT_FOUND : HttpResponse::HTTP_GONE,
            );
        }

        $report = InspectionReport::query()->find($resolved['link']->inspection_report_id);
        $bytes = $report === null ? null : $this->reports->bytes($report);

        if ($report === null || $bytes === null) {
            return response('This report is not available.', HttpResponse::HTTP_NOT_FOUND);
        }

        return response($bytes, HttpResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $report->reference . '.pdf',
                'report-' . $report->id . '.pdf',
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
