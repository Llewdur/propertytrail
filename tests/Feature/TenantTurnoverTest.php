<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\AuditLog;
use App\Models\CapturePasskey;
use App\Models\CaptureReminder;
use App\Models\EvidenceLink;
use App\Models\Lease;
use App\Models\SharedConditionPhoto;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/** A real JPEG carrying an EXIF camera make, as a phone photo would. */
function jpeg_with_exif(string $make = 'OldTenantPhone'): string
{
    $image = imagecreatetruecolor(12, 8);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    $value = $make . "\0";
    $tiff =
        'II*'
        . "\0"
        . pack('V', 8)
        . pack('v', 1)
        . pack('vvVV', 0x010F, 2, strlen($value), 26)
        . pack('V', 0)
        . $value;
    $exif = 'Exif' . "\0\0" . $tiff;
    $segment = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;

    return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
}

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('turnover');
    $this->organisation->settings = [...($this->organisation->settings ?? []), 'plan' => 'starter'];
    $this->organisation->save();
    $this->storage = new FakeStorageAdapter();
    app()->instance(StorageAdapterInterface::class, $this->storage);

    $this->staff = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $this->property = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Turnover House'))
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

    $this->makeLease = fn(string $reference, string $tenant, array $extra = []) => ($this->staff)()->postJson(
        '/api/v1/properties/' . $this->property['id'] . '/leases',
        [
            'lease_reference' => $reference,
            'tenant_name' => $tenant,
            'start_date' => '2026-01-01',
            'status' => 'active',
            ...$extra,
        ],
    );

    $this->old = ($this->makeLease)('LEASE-OLD', 'Olive Oldtenant')->assertCreated()->json('data');

    $this->issue = function (int $leaseId): string {
        $url = ($this->staff)()
            ->postJson('/api/v1/leases/' . $leaseId . '/capture-link', ['phase' => 'incoming'])
            ->assertCreated()
            ->json('data.url');

        return (string) parse_url((string) $url, PHP_URL_FRAGMENT);
    };

    $this->tenant = function (#[\SensitiveParameter] string $token) {
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
        resetTenant();

        return $this->withHeaders(['X-Capture-Token' => $token, 'Accept' => 'application/json']);
    };

    // The earlier tenant's move-out photo of the kitchen, with camera metadata.
    ($this->staff)()
        ->postJson('/api/v1/evidence/upload', [
            'file' => UploadedFile::fake()->createWithContent('olive-kitchen.jpg', jpeg_with_exif()),
            'attachable_type' => 'lease',
            'attachable_id' => $this->old['id'],
            'area_room_id' => $this->room['id'],
            'phase' => 'outgoing',
            'label' => 'Olive says the tap drips',
        ])
        ->assertCreated();
    $this->oldPhoto = EvidenceLink::query()->allOrganisations()->where('attachable_id', $this->old['id'])->sole();
});

it('ends the old tenant link, unlock and reminders on move-out, and keeps the record', function (): void {
    $token = ($this->issue)($this->old['id']);
    CapturePasskey::query()->create([
        'organisation_id' => $this->organisation->id,
        'lease_id' => $this->old['id'],
        'phase' => 'incoming',
        'credential_id' => 'phone-1',
        'credential' => ['id' => 'phone-1'],
    ]);
    CaptureReminder::query()->create([
        'organisation_id' => $this->organisation->id,
        'lease_id' => $this->old['id'],
        'phase' => 'incoming',
        'fallback_at' => now(),
    ]);

    ($this->staff)()
        ->postJson('/api/v1/leases/' . $this->old['id'] . '/move-out', ['end_date' => '2026-11-30'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ended');

    $lease = Lease::query()->allOrganisations()->findOrFail($this->old['id']);

    expect($lease->end_date?->toDateString())
        ->toBe('2026-11-30')
        ->and($lease->moved_out_at)
        ->not
        ->toBeNull()
        ->and(CapturePasskey::query()->allOrganisations()->count())
        ->toBe(0)
        ->and(CaptureReminder::query()->allOrganisations()->sole()->stop_reason)
        ->toBe('moved_out')
        ->and(EvidenceLink::query()->allOrganisations()->count())
        ->toBe(1)
        ->and(AuditLog::query()->where('event', 'tenant_moved_out')->count())
        ->toBe(1);

    ($this->tenant)($token)->getJson('/api/v1/capture')->assertGone();

    ($this->staff)()->postJson('/api/v1/leases/' . $this->old['id'] . '/capture-link', [
        'phase' => 'outgoing',
    ])->assertConflict();
    ($this->staff)()->postJson('/api/v1/leases/' . $this->old['id'] . '/move-out')->assertConflict();
});

it('starts a new tenancy on the earlier one room list, from the same property only', function (): void {
    $hall = ($this->staff)()
        ->postJson('/api/v1/properties/' . $this->property['id'] . '/rooms', [
            'name' => 'Hall',
            'room_type' => 'other',
            'sort_order' => 2,
        ])
        ->assertCreated()
        ->json('data');
    ($this->staff)()->putJson('/api/v1/leases/' . $this->old['id'] . '/areas', ['area_ids' => [
        $hall['id'],
    ]])->assertOk();

    $new = ($this->makeLease)('LEASE-NEW', 'Nia Newtenant', [
        'previous_lease_id' => $this->old['id'],
    ])->assertCreated()->json('data');

    expect(
        Lease::query()
            ->allOrganisations()
            ->findOrFail($new['id'])
            ->coveredAreas()
            ->pluck('areas_rooms.id')
            ->all(),
    )->toBe([$hall['id']]);

    $other = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Elsewhere'))
        ->assertCreated()
        ->json('data');

    ($this->staff)()
        ->postJson('/api/v1/properties/' . $other['id'] . '/leases', [
            'lease_reference' => 'LEASE-X',
            'tenant_name' => 'X',
            'start_date' => '2026-01-01',
            'previous_lease_id' => $this->old['id'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('previous_lease_id');
});

it('shows the new tenant nothing from the earlier tenancy by default', function (): void {
    $new = ($this->makeLease)('LEASE-NEW', 'Nia Newtenant')->assertCreated()->json('data');
    $token = ($this->issue)($new['id']);

    $body = ($this->tenant)($token)->getJson('/api/v1/capture')->assertOk();

    expect($body->json('data.shared_condition'))->toBe([])->and($body->getContent())->not->toContain('Olive');

    ($this->tenant)($token)->getJson('/api/v1/capture/photos/' . $this->oldPhoto->id)->assertNotFound();
    ($this->tenant)($token)->getJson('/api/v1/capture/history')->assertOk()->assertDontSee('Olive');
});

it('shares an approved copy with no metadata and only allow-listed fields', function (): void {
    $new = ($this->makeLease)('LEASE-NEW', 'Nia Newtenant')->assertCreated()->json('data');

    $shared = ($this->staff)()
        ->postJson('/api/v1/leases/' . $new['id'] . '/shared-photos', [
            'evidence_link_id' => $this->oldPhoto->id,
            'description' => 'Kitchen tap at the end of the last tenancy.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.approved_by_user_id', $this->user->id)
        ->json('data');

    $token = ($this->issue)($new['id']);
    $body = ($this->tenant)($token)->getJson('/api/v1/capture')->assertOk();

    expect($body->json('data.shared_condition'))
        ->toBe([[
            'id' => $shared['id'],
            'area_room_id' => $this->room['id'],
            'room_name' => 'Kitchen',
            'description' => 'Kitchen tap at the end of the last tenancy.',
            'response' => null,
            'response_note' => null,
        ]])
        ->and($body->getContent())
        ->not->toContain('Olive')
        ->not->toContain('LEASE-OLD');

    $copy = ($this->tenant)($token)->get('/api/v1/capture/shared-photos/' . $shared['id'])->assertOk();
    $original = (string) $this->storage->get($this->oldPhoto->evidenceRecord->storage_path);

    expect(exif_read_data('data://image/jpeg;base64,' . base64_encode($original)))
        ->toHaveKey('Make', 'OldTenantPhone')
        ->and((string) $copy->getContent())
        ->not->toContain('OldTenantPhone')
        ->not->toContain("Exif\0\0")->toStartWith("\xFF\xD8")->and(hash(
            'sha256',
            $original,
        ))->toBe($this->oldPhoto->evidenceRecord->sha256_hash);
});

it('uses a staff cropped copy in place of the original pixels', function (): void {
    $new = ($this->makeLease)('LEASE-NEW', 'Nia Newtenant')->assertCreated()->json('data');

    ($this->staff)()
        ->postJson('/api/v1/leases/' . $new['id'] . '/shared-photos', [
            'evidence_link_id' => $this->oldPhoto->id,
            'file' => UploadedFile::fake()->image('blurred.jpg', 30, 20),
        ])
        ->assertCreated()
        ->assertJsonPath('data.edited', true);

    $photo = SharedConditionPhoto::query()->allOrganisations()->sole();

    $size = getimagesizefromstring((string) $this->storage->get($photo->storage_path));

    expect($size)->not->toBeFalse()->and([$size[0], $size[1]])->toBe([30, 20]);
});

it('refuses a photo from this tenancy or another property', function (): void {
    $new = ($this->makeLease)('LEASE-NEW', 'Nia Newtenant')->assertCreated()->json('data');
    $other = ($this->staff)()
        ->postJson('/api/v1/properties', propertyPayload('Elsewhere'))
        ->assertCreated()
        ->json('data');
    $otherLease = ($this->staff)()
        ->postJson('/api/v1/properties/' . $other['id'] . '/leases', [
            'lease_reference' => 'LEASE-X',
            'tenant_name' => 'X',
            'start_date' => '2026-01-01',
        ])
        ->assertCreated()
        ->json('data');

    ($this->staff)()
        ->postJson('/api/v1/leases/' . $otherLease['id'] . '/shared-photos', [
            'evidence_link_id' => $this->oldPhoto->id,
        ])
        ->assertUnprocessable();
    ($this->staff)()->postJson('/api/v1/leases/' . $this->old['id'] . '/shared-photos', [
        'evidence_link_id' => $this->oldPhoto->id,
    ])->assertUnprocessable();

    expect(SharedConditionPhoto::query()->allOrganisations()->count())->toBe(0);
});

it('keeps a withdrawn or another tenancy share out of the tenant reach', function (): void {
    $new = ($this->makeLease)('LEASE-NEW', 'Nia Newtenant')->assertCreated()->json('data');
    $third = ($this->makeLease)('LEASE-3', 'Third Tenant')->assertCreated()->json('data');
    $share = fn(int $leaseId) => ($this->staff)()
        ->postJson('/api/v1/leases/' . $leaseId . '/shared-photos', ['evidence_link_id' => $this->oldPhoto->id])
        ->assertCreated()
        ->json('data.id');

    $mine = $share($new['id']);
    $theirs = $share($third['id']);

    ($this->staff)()->deleteJson('/api/v1/leases/' . $new['id'] . '/shared-photos/' . $mine)->assertOk();

    $token = ($this->issue)($new['id']);
    ($this->tenant)($token)->getJson('/api/v1/capture')->assertOk()->assertJsonPath('data.shared_condition', []);
    ($this->tenant)($token)->get('/api/v1/capture/shared-photos/' . $mine)->assertNotFound();
    ($this->tenant)($token)->get('/api/v1/capture/shared-photos/' . $theirs)->assertNotFound();
    ($this->tenant)($token)->postJson('/api/v1/capture/shared-photos/' . $theirs . '/response', [
        'response' => 'confirmed',
    ])->assertNotFound();
});

it('lets the new tenant confirm or dispute the shared condition', function (): void {
    $new = ($this->makeLease)('LEASE-NEW', 'Nia Newtenant')->assertCreated()->json('data');
    $id = ($this->staff)()
        ->postJson('/api/v1/leases/' . $new['id'] . '/shared-photos', ['evidence_link_id' => $this->oldPhoto->id])
        ->assertCreated()
        ->json('data.id');
    $token = ($this->issue)($new['id']);
    $respond = fn(array $body) => ($this->tenant)($token)->postJson(
        '/api/v1/capture/shared-photos/' . $id . '/response',
        $body,
    );

    $respond(['response' => 'disputed'])->assertUnprocessable()->assertJsonValidationErrors('note');
    $respond(['response' => 'disputed', 'note' => 'The tap is now cracked too.'])
        ->assertOk()
        ->assertJsonPath('data.0.response', 'disputed')
        ->assertJsonPath('data.0.response_note', 'The tap is now cracked too.');

    expect(AuditLog::query()->where('lease_id', $new['id'])->where('event', 'shared_photo_disputed')->count())->toBe(1);

    ($this->staff)()
        ->getJson('/api/v1/leases/' . $new['id'] . '/shared-photos')
        ->assertOk()
        ->assertJsonPath('data.0.tenant_response', 'disputed');
});
