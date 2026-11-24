<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Factories\InspectionWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\ReportAccessLog;
use App\Models\ReportLink;
use App\Support\Tenancy\CurrentOrganisation;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function reportSignature(): string
{
    $image = imagecreatetruecolor(120, 40);
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
}

beforeEach(function (): void {
    resetTenant();
    $this->storage = new FakeStorageAdapter();
    app()->instance(StorageAdapterInterface::class, $this->storage);

    ['organisation' => $organisation, 'user' => $user] = tenantUser('alpha');
    app(CurrentOrganisation::class)->set($organisation->id);
    $property = app(PropertyWriteFactory::class)->create(propertyPayload('Main House'));
    $this->inspection = app(InspectionWriteFactory::class)->create($property, 'incoming', null, null);
    $this->inspection->forceFill(['status' => Inspection::STATUS_SUBMITTED, 'submitted_at' => now()])->save();
    resetTenant();

    $this->asOwner = fn() => withHeader('X-Organisation-Id', (string) $organisation->id)->actingAs($user);
    $this->sign = fn(string $party) => ($this->asOwner)()
        ->postJson("/api/v1/inspections/{$this->inspection->id}/signatures", [
            'party' => $party,
            'signer_name' => ucfirst($party) . ' Person',
            'signature' => reportSignature(),
        ])
        ->assertCreated();
});

it('does not generate a report until both parties have signed', function (): void {
    ($this->sign)('landlord');

    expect($this->storage->objects)->toBe([]);
    ($this->asOwner)()->getJson("/api/v1/inspections/{$this->inspection->id}/report")->assertNotFound();
});

it('locks a valid PDF once both parties sign and every download is byte-identical', function (): void {
    ($this->sign)('landlord');
    ($this->sign)('tenant');

    $report = ($this->asOwner)()->getJson("/api/v1/inspections/{$this->inspection->id}/report")->assertOk();
    expect($report->json('data.reference'))->toStartWith('PT-');

    $first = ($this->asOwner)()->get("/api/v1/inspections/{$this->inspection->id}/report/download")->assertOk();
    $second = ($this->asOwner)()->get("/api/v1/inspections/{$this->inspection->id}/report/download")->assertOk();

    $bytes = $first->getContent();
    expect($bytes)
        ->toStartWith('%PDF')
        ->and($second->getContent())
        ->toBe($bytes)
        ->and(hash('sha256', $bytes))
        ->toBe($report->json('data.sha256'))
        ->and(count($this->storage->objects))
        ->toBe(1);
});

it('never regenerates a locked report', function (): void {
    ($this->sign)('landlord');
    ($this->sign)('tenant');

    $path = array_key_first($this->storage->objects);
    $locked = $this->storage->objects[$path];

    app(\App\Services\ReportService::class)->lockWhenFullySigned($this->inspection);

    expect($this->storage->objects[$path])->toBe($locked);
});

it('records generate, lock, download and link events in the audit log', function (): void {
    ($this->sign)('landlord');
    ($this->sign)('tenant');
    ($this->asOwner)()->get("/api/v1/inspections/{$this->inspection->id}/report/download")->assertOk();
    ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/report/link")->assertCreated();

    expect(
        AuditLog::query()
            ->whereIn('event', [
                'report_generated',
                'report_locked',
                'report_downloaded',
                'report_link_issued',
            ])
            ->pluck('event')
            ->sort()
            ->values()
            ->all(),
    )->toBe([
        'report_downloaded',
        'report_generated',
        'report_link_issued',
        'report_locked',
    ]);
});

it('serves the same bytes on the public link without an account and logs every access', function (): void {
    ($this->sign)('landlord');
    ($this->sign)('tenant');

    $downloaded = ($this->asOwner)()->get("/api/v1/inspections/{$this->inspection->id}/report/download")->getContent();
    $link = ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/report/link")->assertCreated();
    $token = substr((string) $link->json('data.url'), strrpos((string) $link->json('data.url'), '/') + 1);

    resetTenant();
    $this->app['auth']->forgetGuards();

    $public = get("/r/{$token}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($public->getContent())->toBe($downloaded);

    get('/r/not-a-real-token')->assertNotFound();

    expect(ReportAccessLog::query()->pluck('outcome')->all())->toBe(['ok', 'unknown']);
});

it('refuses an expired or revoked public link', function (): void {
    ($this->sign)('landlord');
    ($this->sign)('tenant');

    $issue = fn() => (string) ($this->asOwner)()
        ->postJson("/api/v1/inspections/{$this->inspection->id}/report/link")
        ->json('data.url');
    $expired = basename($issue());
    $revoked = basename($issue());

    ReportLink::query()
        ->allOrganisations()
        ->where('token_hash', ReportLink::hash($expired))
        ->update([
            'expires_at' => now()->subMinute(),
        ]);
    ReportLink::query()
        ->allOrganisations()
        ->where('token_hash', ReportLink::hash($revoked))
        ->update([
            'revoked_at' => now(),
        ]);

    resetTenant();
    $this->app['auth']->forgetGuards();

    get("/r/{$expired}")->assertStatus(410);
    get("/r/{$revoked}")->assertStatus(410);
    expect(ReportAccessLog::query()->pluck('outcome')->all())->toBe(['expired', 'revoked']);
});

it('locks the report when the second party signs from a link', function (): void {
    ($this->sign)('landlord');
    $issued = ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/signing-links", [
        'party' => 'tenant',
    ])->assertCreated();
    $token = basename((string) $issued->json('data.url'));

    resetTenant();
    $this->app['auth']->forgetGuards();

    postJson("/api/v1/sign/{$token}", ['signer_name' => 'Tom', 'signature' => reportSignature()])->assertCreated();

    expect(array_keys($this->storage->objects))->toHaveCount(1);
});

it('keeps the signature and retries the lock when storage fails', function (): void {
    $failing = new class implements StorageAdapterInterface {
        public bool $fail = true;

        /** @var array<string, string> */
        public array $objects = [];

        public function put(string $path, string $contents): void
        {
            if ($this->fail) {
                throw new RuntimeException('down');
            }

            $this->objects[$path] = $contents;
        }

        public function get(string $path): ?string
        {
            return $this->objects[$path] ?? null;
        }

        public function exists(string $path): bool
        {
            return isset($this->objects[$path]);
        }

        public function delete(string $path): void
        {
            unset($this->objects[$path]);
        }
    };
    app()->instance(StorageAdapterInterface::class, $failing);

    ($this->sign)('landlord');
    ($this->sign)('tenant');

    expect($failing->objects)->toBe([]);
    $this->assertDatabaseCount('inspection_reports', 0);
    $this->assertDatabaseCount('inspection_signatures', 2);

    $failing->fail = false;
    ($this->asOwner)()->getJson("/api/v1/inspections/{$this->inspection->id}/report")->assertOk();
    expect($failing->objects)->toHaveCount(1);
});

it('lets the organisation revoke public report links', function (): void {
    ($this->sign)('landlord');
    ($this->sign)('tenant');

    $token = basename(
        (string) ($this->asOwner)()
            ->postJson("/api/v1/inspections/{$this->inspection->id}/report/link")
            ->json('data.url'),
    );

    ($this->asOwner)()
        ->deleteJson("/api/v1/inspections/{$this->inspection->id}/report/link")
        ->assertOk()
        ->assertJsonPath('data.revoked', 1);

    resetTenant();
    $this->app['auth']->forgetGuards();

    get("/r/{$token}")->assertStatus(410);
});

it('does not let another organisation read or fetch the report', function (): void {
    ($this->sign)('landlord');
    ($this->sign)('tenant');

    ['organisation' => $other, 'user' => $outsider] = tenantUser('beta');
    $asOutsider = fn() => withHeader('X-Organisation-Id', (string) $other->id)->actingAs($outsider);

    $asOutsider()->getJson("/api/v1/inspections/{$this->inspection->id}/report")->assertNotFound();
    $asOutsider()->get("/api/v1/inspections/{$this->inspection->id}/report/download")->assertNotFound();
    $asOutsider()->postJson("/api/v1/inspections/{$this->inspection->id}/report/link")->assertNotFound();
});
