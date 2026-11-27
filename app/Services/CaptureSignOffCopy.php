<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaptureSignOff;
use Symfony\Component\HttpFoundation\Response;

/**
 * The PDF copy of one sign-off, for the tenant and for staff. It shows only what that
 * tenant signed: no selfie, no IP address and nothing from another lease.
 */
final class CaptureSignOffCopy
{
    public function __construct(
        private readonly PdfRenderer $pdf,
    ) {}

    public function render(CaptureSignOff $signOff): string
    {
        $superseded = CaptureSignOff::query()
            ->where('lease_id', $signOff->lease_id)
            ->where('phase', $signOff->phase)
            ->where('version', '>', $signOff->version)
            ->exists();

        return $this->pdf->render(
            view('reports.capture-sign-off', [
                'signOff' => $signOff,
                'reference' => $signOff->reference(),
                'superseded' => $superseded,
            ])->render(),
        );
    }

    public function download(CaptureSignOff $signOff): Response
    {
        return response($this->render($signOff), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $signOff->reference() . '.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
