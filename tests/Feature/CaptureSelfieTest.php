<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Factories\UserWriteFactory;
use App\Models\AuditLog;
use App\Models\CaptureSelfie;
use App\Models\EvidenceRecord;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;
use Zekini\Auth\Enums\TermsAcceptanceSource;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('selfie');
    $this->storage = new FakeStorageAdapter();
    app()->instance(StorageAdapterInterface::class, $this->storage);

    $this->staff = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $property = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Selfie House'))
        ->assertCreated()
        ->json('data');

    $this->lease = ($this->staff)()
        ->postJson('/api/v1/properties/' . $property['id'] . '/leases', [
            'lease_reference' => 'LEASE-32',
            'tenant_name' => 'S. Tenant',
            'start_date' => '2026-01-01',
            'status' => 'active',
        ])
        ->assertCreated()
        ->json('data');

    $url = ($this->staff)()
        ->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link', ['phase' => 'incoming'])
        ->assertCreated()
        ->json('data.url');
    $this->token = (string) parse_url((string) $url, PHP_URL_FRAGMENT);

    $this->tenant = function () {
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        resetTenant();

        return $this->withHeaders(['X-Capture-Token' => $this->token, 'Accept' => 'application/json']);
    };

    $this->selfie = fn(int $width = 12) => ($this->tenant)()->post('/api/v1/capture/selfie', [
        'file' => UploadedFile::fake()->image('selfie.jpg', $width, 12),
    ]);
});

it('stores the selfie once, privately and apart from the evidence', function (): void {
    ($this->tenant)()->getJson('/api/v1/capture')->assertOk()->assertJsonPath('data.selfie_taken', false);

    ($this->selfie)()->assertCreated()->assertHeader('Cache-Control', 'no-store, private');
    ($this->selfie)()->assertCreated();

    $selfie = CaptureSelfie::query()->allOrganisations()->sole();

    expect($selfie->organisation_id)
        ->toBe($this->organisation->id)
        ->and($selfie->phase)
        ->toBe('incoming')
        ->and(hash('sha256', (string) $this->storage->get($selfie->storage_path)))
        ->toBe($selfie->sha256_hash)
        ->and(EvidenceRecord::query()->allOrganisations()->count())
        ->toBe(0);

    ($this->tenant)()->getJson('/api/v1/capture')->assertOk()->assertJsonPath('data.selfie_taken', true);
});

it('refuses a file that is not an image, a dead link and more than the cap', function (): void {
    ($this->tenant)()
        ->post('/api/v1/capture/selfie', [
            'file' => UploadedFile::fake()->createWithContent('page.html', '<script></script>'),
        ])
        ->assertUnprocessable();

    foreach (range(1, CaptureSelfie::MAX_PER_PHASE) as $width) {
        ($this->selfie)($width)->assertCreated();
    }

    ($this->selfie)(99)->assertUnprocessable();

    ($this->staff)()->deleteJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link')->assertOk();
    ($this->selfie)(98)->assertGone();

    expect(CaptureSelfie::query()->allOrganisations()->count())->toBe(CaptureSelfie::MAX_PER_PHASE);
});

it('shows the selfie to staff who may contribute, audits each view, and hides it from viewers', function (): void {
    ($this->selfie)()->assertCreated();
    $selfie = CaptureSelfie::query()->allOrganisations()->sole();
    $base = '/api/v1/leases/' . $this->lease['id'] . '/capture-selfies';

    ($this->staff)()->getJson($base)->assertOk()->assertJsonPath('data.0.id', $selfie->id);
    ($this->staff)()
        ->get($base . '/' . $selfie->id)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(AuditLog::query()->where('event', 'capture_selfie_viewed')->count())->toBe(1);

    $users = app(UserWriteFactory::class);
    $viewer = $users->create([
        'name' => 'Viewer',
        'email' => 'viewer-32@example.test',
        'password' => fake()->password(12),
    ]);
    $users->attachToOrganisation($viewer, $this->organisation, 'viewer');
    $users->acceptTerms($viewer, TermsAcceptanceSource::Register);

    actingAs($viewer)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->get($base . '/' . $selfie->id)
        ->assertForbidden();

    ['organisation' => $otherOrganisation, 'user' => $otherUser] = tenantUser('selfie-other');

    actingAs($otherUser)
        ->withHeader('X-Organisation-Id', (string) $otherOrganisation->id)
        ->get($base . '/' . $selfie->id)
        ->assertNotFound();
});
