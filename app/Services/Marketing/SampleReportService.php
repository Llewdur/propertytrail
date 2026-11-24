<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Services\PdfRenderer;
use App\Support\Legal;

final class SampleReportService
{
    private const STAMP = 'SAMPLE REPORT · FICTIONAL PROPERTY, PEOPLE AND PHOTOS · NOT A REAL INSPECTION';

    public function __construct(
        private readonly PdfRenderer $pdf,
    ) {}

    public function html(): string
    {
        return view('reports.marketing-sample', [
            'reference' => 'PT-SAMPLE-V1',
            'stamp' => self::STAMP,
            'watermark' => 'SAMPLE',
            'organisationName' => 'Example Estates (fictional)',
            'phase' => 'outgoing',
            'inspectedAt' => '14 April 2026, 10:30',
            'property' => [
                'name' => 'Fictional seaside flat',
                'address_line_1' => '14 Kestrel Avenue',
                'city' => 'Cape Town',
                'postal_code' => '7700',
            ],
            'lease' => ['lease_reference' => 'SAMPLE-LEASE-001'],
            'landlord' => [
                'signer_name' => 'Sample Property Manager',
                'signed_at' => '14 April 2026, 11:05',
            ],
            'tenant' => [
                'signer_name' => 'Sample Tenant',
                'signed_at' => '14 April 2026, 11:20',
            ],
            'notes' => 'Representative condition notes from a fictional move-out inspection. No real property, person or photograph appears in this document.',
            'rooms' => $this->rooms(),
            'footer' => Legal::REPORT_DISCLAIMER,
        ])->render();
    }

    public function bytes(): string
    {
        return $this->pdf->render($this->html());
    }

    /** @return list<array{title: string, items: list<array{label: string, condition: string, note: string}>}> */
    private function rooms(): array
    {
        return [
            [
                'title' => 'Entrance / Hallway',
                'items' => [
                    [
                        'label' => 'Walls',
                        'condition' => 'Good',
                        'note' => 'Clean, with a small scuff near the switch (approx. 4 cm).',
                    ],
                    ['label' => 'Floors', 'condition' => 'Excellent', 'note' => 'No marks observed.'],
                    ['label' => 'Doors', 'condition' => 'Good', 'note' => 'Latch closes normally.'],
                    ['label' => 'Lights', 'condition' => 'Good', 'note' => 'All fittings working.'],
                ],
            ],
            [
                'title' => 'Lounge',
                'items' => [
                    [
                        'label' => 'Walls',
                        'condition' => 'Fair',
                        'note' => 'Touch-up required above the skirting (approx. 20 cm).',
                    ],
                    ['label' => 'Floors', 'condition' => 'Good', 'note' => 'Light wear in the walking area.'],
                    ['label' => 'Windows', 'condition' => 'Good', 'note' => 'Opens and locks; one handle loose.'],
                    ['label' => 'Ceiling', 'condition' => 'Excellent', 'note' => 'No stains observed.'],
                    ['label' => 'Lights', 'condition' => 'Good', 'note' => 'All fittings working.'],
                ],
            ],
            [
                'title' => 'Kitchen',
                'items' => [
                    ['label' => 'Counters', 'condition' => 'Good', 'note' => 'Clean; no chips observed.'],
                    ['label' => 'Cupboards', 'condition' => 'Fair', 'note' => 'One door needs alignment.'],
                    ['label' => 'Sink', 'condition' => 'Good', 'note' => 'Drains freely.'],
                    ['label' => 'Appliances', 'condition' => 'Good', 'note' => 'Stove and extractor tested.'],
                    ['label' => 'Floors', 'condition' => 'Good', 'note' => 'No loose tiles.'],
                    ['label' => 'Lights', 'condition' => 'Excellent', 'note' => 'All fittings working.'],
                ],
            ],
            [
                'title' => 'Bedroom',
                'items' => [
                    [
                        'label' => 'Walls',
                        'condition' => 'Good',
                        'note' => 'Small mark behind the door (as at move-in).',
                    ],
                    ['label' => 'Floors', 'condition' => 'Good', 'note' => 'Light wear at the doorway.'],
                    ['label' => 'Windows', 'condition' => 'Good', 'note' => 'Opens and locks.'],
                    ['label' => 'Cupboards', 'condition' => 'Fair', 'note' => 'One shelf missing.'],
                    ['label' => 'Lights', 'condition' => 'Good', 'note' => 'All fittings working.'],
                ],
            ],
            [
                'title' => 'Bathroom',
                'items' => [
                    ['label' => 'Toilet', 'condition' => 'Good', 'note' => 'Flushes normally.'],
                    ['label' => 'Basin', 'condition' => 'Good', 'note' => 'No leaks observed.'],
                    [
                        'label' => 'Shower / Bath',
                        'condition' => 'Fair',
                        'note' => 'Silicone seal needs renewal (approx. 40 cm).',
                    ],
                    ['label' => 'Tiles', 'condition' => 'Good', 'note' => 'No cracked tiles.'],
                    ['label' => 'Lights', 'condition' => 'Good', 'note' => 'All fittings working.'],
                ],
            ],
        ];
    }
}
