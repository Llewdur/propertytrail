<?php

declare(strict_types=1);

use App\Contracts\Adapters\MailAdapterInterface;
use App\Contracts\Adapters\StorageAdapterInterface;
use App\Factories\UserWriteFactory;
use App\Jobs\SendCaptureLinkJob;
use App\Models\AuditLog;
use App\Models\CaptureLink;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeStorageAdapter;
use Zekini\Auth\Enums\TermsAcceptanceSource;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function capturePhoto(string $name = 'wall.jpg', int $width = 10): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, 10);
}

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('capture');
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());

    $this->staff = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $this->property = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Capture House'))
        ->assertCreated()
        ->json('data');

    $this->room = ($this->staff)()
        ->postJson('/api/v1/properties/' . $this->property['id'] . '/rooms', [
            'name' => 'Kitchen',
            'room_type' => 'kitchen',
            'sort_order' => 1,
        ])
        ->assertCreated()
        ->json('data');

    $this->makeLease = fn(string $reference, string $tenant) => ($this->staff)()
        ->postJson('/api/v1/properties/' . $this->property['id'] . '/leases', [
            'lease_reference' => $reference,
            'tenant_name' => $tenant,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ])
        ->assertCreated()
        ->json('data');

    $this->lease = ($this->makeLease)('LEASE-26', 'T. Tenant');

    $this->issue = function (?int $leaseId = null): string {
        $url = ($this->staff)()
            ->postJson('/api/v1/leases/' . ($leaseId ?? $this->lease['id']) . '/capture-link', ['phase' => 'incoming'])
            ->assertCreated()
            ->json('data.url');

        return (string) parse_url((string) $url, PHP_URL_FRAGMENT);
    };

    // A phone with no account: no signed-in user and no organisation left over from the staff request.
    $this->tenant = function (string $token) {
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        resetTenant();

        return $this->withHeaders(['X-Capture-Token' => $token, 'Accept' => 'application/json']);
    };
});

it('puts the token in the fragment and stores only its hash', function (): void {
    $url = ($this->staff)()
        ->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link', ['phase' => 'incoming'])
        ->assertCreated()
        ->json('data.url');

    $token = (string) parse_url($url, PHP_URL_FRAGMENT);
    $link = CaptureLink::query()->sole();

    expect($url)
        ->toBe(url('/capture') . '#' . $token)
        ->and($link->token_hash)
        ->toBe(hash('sha256', $token))
        ->and((string) json_encode(AuditLog::query()->get()))
        ->not
        ->toContain($token)
        ->and((int) round(now()->diffInDays($link->expires_at)))
        ->toBe(14);
});

it('shows only the lease scope to a tenant with no account', function (): void {
    ($this->staff)()
        ->postJson('/api/v1/evidence/upload', [
            'file' => capturePhoto('staff-only.jpg', 33),
            'attachable_type' => 'lease',
            'attachable_id' => $this->lease['id'],
            'area_room_id' => $this->room['id'],
            'phase' => 'incoming',
        ])
        ->assertCreated();

    $token = ($this->issue)();
    $data = ($this->tenant)($token)->getJson('/api/v1/capture')->assertOk()->json('data');

    expect(array_keys($data))
        ->toBe([
            'capture_key',
            'property_name',
            'phase',
            'expires_at',
            'passkey_registered',
            'locked',
            'tenant_name',
            'selfie_taken',
            'notifications',
            'areas',
        ])
        ->and($data['areas'])
        ->toBe([['id' => $this->room['id'], 'name' => 'Kitchen', 'photos' => []]]);

    $staffPhoto = EvidenceLink::query()->allOrganisations()->sole();
    ($this->tenant)($token)->getJson('/api/v1/capture/photos/' . $staffPhoto->id)->assertNotFound();
});

it('accepts a signed-out photo once, even when the upload is retried', function (): void {
    $token = ($this->issue)();

    $upload = fn() => ($this->tenant)($token)->post('/api/v1/capture/photos', [
        'file' => capturePhoto(),
        'area_room_id' => $this->room['id'],
    ]);

    $first = $upload()->assertCreated()->json('data.id');
    $second = $upload()->assertCreated()->json('data.id');

    $record = EvidenceRecord::query()->allOrganisations()->sole();

    expect($second)
        ->toBe($first)
        ->and(EvidenceLink::query()->allOrganisations()->count())
        ->toBe(1)
        ->and($record->organisation_id)
        ->toBe($this->organisation->id)
        ->and($record->uploaded_by_user_id)
        ->toBeNull();

    ($this->tenant)($token)->get('/api/v1/capture/photos/' . $first)->assertOk();
});

it('refuses an area the lease does not cover and a file that is not an image', function (): void {
    $this->organisation->settings = [...($this->organisation->settings ?? []), 'plan' => 'starter'];
    $this->organisation->save();

    $other = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Other House'))
        ->assertCreated()
        ->json('data');
    $otherRoom = ($this->staff)()
        ->postJson('/api/v1/properties/' . $other['id'] . '/rooms', ['name' => 'Bath', 'room_type' => 'bathroom'])
        ->assertCreated()
        ->json('data');
    $token = ($this->issue)();

    ($this->tenant)($token)->post('/api/v1/capture/photos', [
        'file' => capturePhoto(),
        'area_room_id' => $otherRoom['id'],
    ])->assertUnprocessable();
    ($this->tenant)($token)->post('/api/v1/capture/photos', [
        'file' => UploadedFile::fake()->createWithContent('page.html', '<script></script>'),
        'area_room_id' => $this->room['id'],
    ])->assertUnprocessable();

    expect(EvidenceRecord::query()->allOrganisations()->count())->toBe(0);
});

it('resumes progress on a new link and shuts the old, revoked and expired ones', function (): void {
    $first = ($this->issue)();
    $photoId = ($this->tenant)($first)
        ->post('/api/v1/capture/photos', ['file' => capturePhoto(), 'area_room_id' => $this->room['id']])
        ->assertCreated()
        ->json('data.id');

    $second = ($this->issue)();

    ($this->tenant)($first)->getJson('/api/v1/capture')->assertGone();
    ($this->tenant)($second)
        ->getJson('/api/v1/capture')
        ->assertOk()
        ->assertJsonPath('data.areas.0.photos.0.id', $photoId);
    ($this->tenant)('not-a-token')->getJson('/api/v1/capture')->assertNotFound();

    $this->travel(15)->days();
    ($this->tenant)($second)->getJson('/api/v1/capture')->assertGone();
    $this->travelBack();

    ($this->staff)()->deleteJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link')->assertOk();
    ($this->tenant)($second)->post('/api/v1/capture/photos', [
        'file' => capturePhoto('late.jpg', 20),
        'area_room_id' => $this->room['id'],
    ])->assertGone();
});

it('never shows one tenant the photos of another lease on the same unit', function (): void {
    $other = ($this->makeLease)('LEASE-27', 'O. Other');
    $otherToken = ($this->issue)($other['id']);
    $otherPhoto = ($this->tenant)($otherToken)
        ->post('/api/v1/capture/photos', ['file' => capturePhoto(), 'area_room_id' => $this->room['id']])
        ->assertCreated()
        ->json('data.id');

    $token = ($this->issue)();

    ($this->tenant)($token)->getJson('/api/v1/capture')->assertOk()->assertJsonPath('data.areas.0.photos', []);
    ($this->tenant)($token)->getJson('/api/v1/capture/photos/' . $otherPhoto)->assertNotFound();
});

it('keeps staff capture links inside the organisation and away from viewers', function (): void {
    $users = app(UserWriteFactory::class);
    $viewer = $users->create(['name' => 'Viewer', 'email' => 'viewer-26@example.test', 'password' => 'password']);
    $users->attachToOrganisation($viewer, $this->organisation, 'viewer');
    $users->acceptTerms($viewer, TermsAcceptanceSource::Register);

    actingAs($viewer)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link', ['phase' => 'incoming'])
        ->assertForbidden();

    ['organisation' => $otherOrganisation, 'user' => $otherUser] = tenantUser('capture-other');

    actingAs($otherUser)
        ->withHeader('X-Organisation-Id', (string) $otherOrganisation->id)
        ->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link', ['phase' => 'incoming'])
        ->assertNotFound();

    expect(CaptureLink::query()->allOrganisations()->count())->toBe(0);
});

it('queues the link to the channels the organisation allows', function (): void {
    Queue::fake();

    ($this->staff)()->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link/send', [
        'phase' => 'incoming',
        'phone' => '+27820000000',
    ])->assertUnprocessable();

    $url = ($this->staff)()
        ->postJson('/api/v1/leases/' . $this->lease['id'] . '/capture-link/send', [
            'phase' => 'incoming',
            'email' => 'tenant@example.test',
            'phone' => '+27820000000',
        ])
        ->assertCreated()
        ->assertJsonPath('data.queued', ['mail'])
        ->json('data.url');

    Queue::assertPushed(
        SendCaptureLinkJob::class,
        fn(SendCaptureLinkJob $job): bool => (
            $job->recipient === 'tenant@example.test'
            && str_contains($job->message, $url)
        ),
    );
});

it('does not repeat the message when the provider fails', function (): void {
    $token = ($this->issue)();
    $mail = Mockery::mock(MailAdapterInterface::class);
    $mail->shouldReceive('send')->andThrow(new RuntimeException('rejected body: ' . $token));

    $job = new SendCaptureLinkJob(
        CaptureLink::query()->allOrganisations()->sole()->id,
        'mail',
        'tenant@example.test',
        'link ' . $token,
    );

    try {
        $job->handle($mail, Mockery::mock(App\Contracts\Adapters\WhatsAppAdapterInterface::class));
        $this->fail('The send should have thrown.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain($token)->and($exception->getPrevious())->toBeNull();
    }
});
