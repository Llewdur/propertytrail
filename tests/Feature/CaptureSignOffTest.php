<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\AuditLog;
use App\Models\CaptureSelfie;
use App\Models\CaptureSignOff;
use App\Support\CaptureDeclarations;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('sign-off');
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());

    $this->staff = function () {
        $this->flushHeaders();

        return actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);
    };

    $property = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Signed House'))
        ->assertCreated()
        ->json('data');

    $this->lease = ($this->staff)()
        ->postJson('/api/v1/properties/' . $property['id'] . '/leases', [
            'lease_reference' => 'LEASE-37',
            'tenant_name' => 'Thandi Tenant',
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

    $image = imagecreatetruecolor(120, 40);
    ob_start();
    imagepng($image);
    $this->signature = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());

    $this->hall = ['room_key' => 'hall', 'room_name' => 'Hall'];
    ($this->tenant)()
        ->post('/api/v1/capture/photos', [
            ...$this->hall,
            'file' => UploadedFile::fake()->image('hall.jpg', 10, 10),
        ])
        ->assertCreated();
    ($this->tenant)()->post('/api/v1/capture/notes', [...$this->hall, 'condition' => 'good'])->assertCreated();

    $this->review = fn() => ($this->tenant)()->getJson('/api/v1/capture/sign-off')->assertOk()->json('data');

    $this->sign = fn(array $overrides = []) => ($this->tenant)()->post('/api/v1/capture/sign-off', [
        'signer_name' => 'Thandi Tenant',
        'signature' => $this->signature,
        'content_hash' => ($this->review)()['content_hash'],
        'declaration_version' => CaptureDeclarations::VERSION,
        'confirm_identity' => '1',
        'confirm_accuracy' => '1',
        'selfie' => UploadedFile::fake()->image('me.jpg', 12, 12),
        ...$overrides,
    ]);
});

it('shows the tenant a summary to review and records the signed, hashed version with its evidence', function (): void {
    $review = ($this->review)();

    expect($review['status'])
        ->toBe('unsigned')
        ->and($review['rooms'])
        ->toHaveCount(1)
        ->and($review['rooms'][0])
        ->toMatchArray(['name' => 'Hall', 'condition' => 'good', 'photo_count' => 1])
        ->and($review['declarations'][0]['text'])
        ->toContain('Thandi Tenant');

    ($this->sign)(['comment' => 'The geyser drips.'])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.content_hash', $review['content_hash']);

    $signOff = CaptureSignOff::query()->allOrganisations()->sole();

    expect($signOff->organisation_id)
        ->toBe($this->organisation->id)
        ->and($signOff->declarations)
        ->toBe($review['declarations'])
        ->and($signOff->comment)
        ->toBe('The geyser drips.')
        ->and($signOff->capture_selfie_id)
        ->toBe(CaptureSelfie::query()->allOrganisations()->sole()->id)
        ->and($signOff->phone_unlocked)
        ->toBeFalse()
        ->and(AuditLog::query()->where('event', 'capture_signed_off')->count())
        ->toBe(1)
        ->and(($this->review)()['status'])
        ->toBe('signed');

    ($this->sign)()->assertUnprocessable()->assertJsonPath('message', 'You have already signed this record.');
});

it('refuses a sign-off without both declarations or for content that changed after review', function (): void {
    ($this->sign)(['confirm_identity' => '0'])->assertUnprocessable()->assertJsonValidationErrors('confirm_identity');
    ($this->sign)(['confirm_accuracy' => null])->assertUnprocessable()->assertJsonValidationErrors('confirm_accuracy');
    ($this->sign)(['content_hash' => str_repeat('0', 64)])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Something changed since you reviewed it. Review it again before you sign.');

    expect(CaptureSignOff::query()->allOrganisations()->count())->toBe(0);
});

it('marks the record changed after a later photo, and a new signature makes a second version', function (): void {
    ($this->sign)()->assertCreated();
    $first = CaptureSignOff::query()->allOrganisations()->sole();

    ($this->tenant)()
        ->post('/api/v1/capture/photos', [
            ...$this->hall,
            'file' => UploadedFile::fake()->image('more.jpg', 11, 11),
        ])
        ->assertCreated();

    expect(($this->review)()['status'])->toBe('changed');

    ($this->sign)()->assertCreated()->assertJsonPath('data.version', 2);

    expect($first->fresh()?->content_hash)
        ->toBe($first->content_hash)
        ->and(($this->review)()['status'])
        ->toBe('signed');

    $listed = ($this->staff)()
        ->getJson('/api/v1/leases/' . $this->lease['id'] . '/capture-sign-offs')
        ->assertOk()
        ->json('data');

    expect(array_column($listed, 'superseded'))->toBe([true, false]);
});

it('gives the tenant and staff a PDF copy, and keeps it from other organisations', function (): void {
    ($this->tenant)()->get('/api/v1/capture/sign-off/copy')->assertNotFound();
    ($this->sign)()->assertCreated();

    ($this->tenant)()
        ->get('/api/v1/capture/sign-off/copy')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $id = CaptureSignOff::query()->allOrganisations()->sole()->id;
    $copy = '/api/v1/leases/' . $this->lease['id'] . '/capture-sign-offs/' . $id . '/copy';

    ($this->staff)()->get($copy)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    ['organisation' => $otherOrganisation, 'user' => $otherUser] = tenantUser('sign-off-other');

    actingAs($otherUser)
        ->withHeader('X-Organisation-Id', (string) $otherOrganisation->id)
        ->get($copy)
        ->assertNotFound();
});
