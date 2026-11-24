<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\InspectionReport;
use App\Models\Organisation;
use App\Services\Marketing\SampleReportService;
use App\Support\Legal;

use function Pest\Laravel\get;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn() => resetTenant());

it('serves the landing page with public security headers', function (): void {
    get('/')
        ->assertOk()
        ->assertSee('Know your property\'s history', false)
        ->assertSee('Why not just use WhatsApp?')
        ->assertSee('/sample-report')
        ->assertSee('/faq')
        ->assertSee('/resources')
        ->assertSee('/privacy')
        ->assertSee('/app')
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'",
        );
});

it('makes no verification or admissibility claim on the landing page', function (): void {
    get('/')->assertSee('does not claim they are independently verified')->assertDontSee('admissible');
});

it('answers the search-intent questions without legal claims', function (): void {
    get('/faq')
        ->assertOk()
        ->assertSee('Can a report be used in court?')
        ->assertSee('record of condition, not a legal opinion')
        ->assertSee('fair wear and tear')
        ->assertSee('missed at move-in')
        ->assertSee('Rental Housing Tribunal')
        ->assertDontSee('admissible');
});

it('lists resources and serves each guide', function (): void {
    get('/resources')
        ->assertOk()
        ->assertSee('Free printable checklist')
        ->assertSee('Counters, cupboards, sink, appliances, floors, lights');

    get('/resources/fair-wear-and-tear')
        ->assertOk()
        ->assertSee('Fair wear and tear')
        ->assertSee('Describe what you can see');

    get('/resources/not-a-guide')->assertNotFound();
});

it('publishes the privacy notice from the repository document', function (): void {
    expect(file_get_contents(base_path(Legal::PRIVACY_FILE)))->toBeString();

    get('/privacy')
        ->assertOk()
        ->assertSee('Privacy notice (POPIA)')
        ->assertSee('private storage')
        ->assertSee('never expose private owner notes')
        ->assertSee('Tenant isolation');
});

it('offers a real downloadable sample PDF', function (): void {
    get('/sample-report')->assertOk()->assertSee('Download the sample PDF')->assertSee('stamped SAMPLE');

    $response = get('/sample-report/download');

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="propertytrail-sample-report.pdf"')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');

    expect($response->headers->get('Cache-Control'))
        ->toContain('no-store')
        ->toContain('no-cache')
        ->and($response->getContent())
        ->toStartWith('%PDF');
});

it('stamps every page of the sample with fictional data and the report disclaimer', function (): void {
    $html = app(SampleReportService::class)->html();

    expect($html)
        ->toContain('SAMPLE REPORT · FICTIONAL PROPERTY, PEOPLE AND PHOTOS · NOT A REAL INSPECTION')
        ->toContain('SAMPLE')
        ->toContain('Kitchen')
        ->toContain('Counters')
        ->toContain('Sample Tenant')
        ->toContain(e(Legal::REPORT_DISCLAIMER));
});

it('generates the sample without writing tenant data or files', function (): void {
    $storage = new class implements StorageAdapterInterface {
        public int $writes = 0;

        public function put(string $path, string $contents): void
        {
            $this->writes++;
        }

        public function get(string $path): ?string
        {
            return null;
        }

        public function exists(string $path): bool
        {
            return false;
        }

        public function delete(string $path): void {}
    };
    app()->instance(StorageAdapterInterface::class, $storage);

    $before = [
        Organisation::query()->count(),
        Inspection::query()->count(),
        InspectionReport::query()->count(),
        AuditLog::query()->count(),
    ];

    get('/sample-report/download')->assertOk();

    expect($storage->writes)
        ->toBe(0)
        ->and([
            Organisation::query()->count(),
            Inspection::query()->count(),
            InspectionReport::query()->count(),
            AuditLog::query()->count(),
        ])
        ->toBe($before);
});
