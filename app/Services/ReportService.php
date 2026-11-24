<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\AuditLog;
use App\Models\EvidenceRecord;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\InspectionReport;
use App\Models\InspectionSection;
use App\Models\InspectionSignature;
use App\Models\Organisation;
use App\Services\Billing\EntitlementService;
use App\Support\Legal;
use Illuminate\Support\Facades\DB;

final class ReportService
{
    /** @var list<string> */
    private const EMBEDDABLE_IMAGES = ['image/jpeg', 'image/png', 'image/gif'];

    public function __construct(
        private readonly StorageAdapterInterface $storage,
        private readonly ReportBrandingService $branding,
        private readonly DeliveryService $deliveries,
        private readonly EntitlementService $billing,
        private readonly PdfRenderer $pdf,
    ) {}

    public function findFor(Inspection $inspection): ?InspectionReport
    {
        return InspectionReport::query()->where('inspection_id', $inspection->id)->orderByDesc('version')->first();
    }

    /**
     * Generates and locks the report once both parties have signed. Idempotent:
     * an inspection that is already locked keeps its stored bytes untouched.
     */
    public function lockWhenFullySigned(Inspection $inspection): ?InspectionReport
    {
        $signed = InspectionSignature::query()
            ->where('inspection_id', $inspection->id)
            ->pluck('party')
            ->unique()
            ->count();

        if ($signed < count(InspectionSignature::PARTIES) || $this->findFor($inspection) !== null) {
            return null;
        }

        $version = 1;
        $reference = sprintf('PT-%06d-V%d', $inspection->id, $version);
        $bytes = $this->render($inspection, $reference);
        $path = sprintf('reports/%d/%d/v%d.pdf', $inspection->organisation_id, $inspection->id, $version);

        $report = DB::transaction(function () use (
            $inspection,
            $version,
            $reference,
            $bytes,
            $path,
        ): ?InspectionReport {
            $locked = Inspection::query()->lockForUpdate()->find($inspection->id);

            if ($locked === null || $this->findFor($locked) !== null) {
                return null;
            }

            $this->storage->put($path, $bytes);

            $report = InspectionReport::query()->create([
                'organisation_id' => $locked->organisation_id,
                'inspection_id' => $locked->id,
                'version' => $version,
                'reference' => $reference,
                'storage_path' => $path,
                'sha256' => hash('sha256', $bytes),
                'size_bytes' => strlen($bytes),
                'locked_at' => now(),
            ]);

            $this->audit($locked, 'report_generated', null, ['reference' => $reference, 'sha256' => $report->sha256]);
            $this->audit($locked, 'report_locked', null, ['reference' => $reference]);

            return $report;
        });

        $this->deliveries->afterLock($inspection, $report);

        return $report;
    }

    /** The stored bytes, exactly as locked. */
    public function bytes(InspectionReport $report): ?string
    {
        return $this->storage->get($report->storage_path);
    }

    public function recordDownload(Inspection $inspection, InspectionReport $report, ?int $userId): void
    {
        $this->audit($inspection, 'report_downloaded', $userId, ['reference' => $report->reference]);
    }

    private function render(Inspection $inspection, string $reference): string
    {
        $inspection->payload();

        $organisation = Organisation::query()->find($inspection->organisation_id);
        $this->billing->recordReportUsage($organisation, $inspection->id);

        $signatures = $inspection->signatures->keyBy('party');

        $rooms = $inspection->sections->map(fn(InspectionSection $section): array => [
            'title' => $section->title,
            'items' => $section->items->map(static fn(InspectionItem $item): array => [
                'label' => $item->label,
                'condition' => $item->condition,
                'note' => $item->note,
            ])->all(),
            'photos' => $this->roomPhotos($section),
        ])->all();

        $html = view('reports.inspection', [
            'reference' => $reference,
            'inspection' => $inspection,
            'organisationName' => $organisation->name ?? '',
            'property' => $inspection->property()->first(),
            'lease' => $inspection->lease_id === null ? null : $inspection->lease()->first(),
            'landlord' => $signatures->get(InspectionSignature::PARTY_LANDLORD),
            'tenant' => $signatures->get(InspectionSignature::PARTY_TENANT),
            'rooms' => $rooms,
            'footer' => Legal::REPORT_DISCLAIMER,
            'branding' => $this->branding->forOrganisation($organisation),
            'generatedAt' => now()->toIso8601String(),
        ])->render();

        return $this->pdf->render($html);
    }

    /** @return list<array{caption: string, data_uri: string}> */
    private function roomPhotos(InspectionSection $section): array
    {
        $photos = [];

        foreach ($section->items as $item) {
            foreach ($item->getRelation('photos') as $photo) {
                if ($photo['superseded'] ?? false) {
                    continue;
                }

                $record = EvidenceRecord::query()->find($photo['evidence_id']);
                $contents = $record === null ? null : $this->storage->get($record->storage_path);

                if (
                    $record === null
                    || $contents === null
                    || !in_array($record->mime_type, self::EMBEDDABLE_IMAGES, true)
                ) {
                    continue;
                }

                $photos[] = [
                    'caption' => trim($item->label . ($photo['caption'] ? ': ' . $photo['caption'] : '')),
                    'data_uri' => 'data:' . $record->mime_type . ';base64,' . base64_encode($contents),
                ];
            }
        }

        return $photos;
    }

    /** @param array<string, mixed> $values */
    private function audit(Inspection $inspection, string $event, ?int $userId, array $values): void
    {
        AuditLog::query()->create([
            'organisation_id' => $inspection->organisation_id,
            'actor_user_id' => $userId,
            'auditable_type' => $inspection->getMorphClass(),
            'auditable_id' => $inspection->id,
            'event' => $event,
            'new_values' => $values,
        ]);
    }
}
