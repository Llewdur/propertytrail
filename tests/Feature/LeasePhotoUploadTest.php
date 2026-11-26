<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Factories\UserWriteFactory;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\Lease;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;
use Zekini\Auth\Enums\TermsAcceptanceSource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function leasePhotoPayload(
    int $leaseId,
    string $contents,
    string $phase,
    ?int $roomId,
    string $takenAt,
    ?int $replaces = null,
    string $filename = 'photo.jpg',
): array {
    $payload = [
        'file' => UploadedFile::fake()->createWithContent($filename, $contents),
        'attachable_type' => 'lease',
        'attachable_id' => $leaseId,
        'phase' => $phase,
        'taken_at' => $takenAt,
    ];

    if ($roomId !== null) {
        $payload['area_room_id'] = $roomId;
    }

    if ($replaces !== null) {
        $payload['replaces_link_id'] = $replaces;
    }

    return $payload;
}

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('lease-photos');

    $this->storage = new FakeStorageAdapter();
    app()->instance(StorageAdapterInterface::class, $this->storage);

    $this->client = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $this->property = ($this->client)()
        ->postJson('/api/v1/properties', propertyPayload('Lease House'))
        ->assertCreated()
        ->json('data');

    $this->room = ($this->client)()
        ->postJson('/api/v1/properties/' . $this->property['id'] . '/rooms', [
            'name' => 'Kitchen',
            'room_type' => 'kitchen',
            'sort_order' => 1,
        ])
        ->assertCreated()
        ->json('data');

    $this->lease = ($this->client)()
        ->postJson('/api/v1/properties/' . $this->property['id'] . '/leases', [
            'lease_reference' => 'LEASE-654-1',
            'tenant_name' => 'A. Tenant',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'active',
        ])
        ->assertCreated()
        ->json('data');
});

it('attaches dated incoming photos to a lease and property grouped by room', function (): void {
    $response = ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'incoming-room-bytes',
            'incoming',
            $this->room['id'],
            '2026-01-02 10:00:00',
            null,
            'move-in-kitchen.jpg',
        ))
        ->assertCreated();

    $recordId = $response->json('data.id');
    $link = EvidenceLink::query()->where('evidence_record_id', $recordId)->sole();

    expect($link->attachable_type)
        ->toBe(new Lease()->getMorphClass())
        ->and($link->attachable_id)
        ->toBe($this->lease['id'])
        ->and($link->phase)
        ->toBe('incoming')
        ->and($link->area_room_id)
        ->toBe($this->room['id'])
        ->and($link->taken_at?->toDateTimeString())
        ->toBe('2026-01-02 10:00:00')
        ->and($link->attachable->property_id)
        ->toBe($this->property['id']);

    $data = ($this->client)()
        ->getJson('/api/v1/properties/' . $this->property['id'] . '/lease-photos')
        ->assertOk()
        ->json('data');

    expect($data['property_id'])
        ->toBe($this->property['id'])
        ->and($data['groups'])
        ->toHaveCount(1)
        ->and($data['groups'][0]['room']['id'])
        ->toBe($this->room['id'])
        ->and($data['groups'][0]['photos'][0]['phase'])
        ->toBe('incoming')
        ->and($data['groups'][0]['photos'][0]['taken_at'])
        ->toStartWith('2026-01-02T10:00:00');
});

it('attaches outgoing photos and returns them beside incoming for comparison', function (): void {
    ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'incoming-comparison-bytes',
            'incoming',
            $this->room['id'],
            '2026-01-02 10:00:00',
            null,
            'comparison-in.jpg',
        ))
        ->assertCreated();

    ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'outgoing-comparison-bytes',
            'outgoing',
            $this->room['id'],
            '2026-12-02 10:00:00',
            null,
            'comparison-out.jpg',
        ))
        ->assertCreated();

    $groups = ($this->client)()
        ->getJson('/api/v1/properties/' . $this->property['id'] . '/lease-photos')
        ->assertOk()
        ->json('data.groups');

    $phases = collect($groups)
        ->flatMap(static fn(array $group): array => $group['photos'])
        ->pluck('phase')
        ->all();

    expect($groups)->toHaveCount(1)->and($phases)->toContain('incoming')->and($phases)->toContain('outgoing');

    $outgoingGroups = ($this->client)()
        ->getJson('/api/v1/properties/' . $this->property['id'] . '/lease-photos?phase=outgoing')
        ->assertOk()
        ->json('data.groups');

    $outgoingPhases = collect($outgoingGroups)
        ->flatMap(static fn(array $group): array => $group['photos'])
        ->pluck('phase')
        ->all();

    expect($outgoingPhases)->toBe(['outgoing']);
});

it('shows lease photos as property documents and returns lease detail', function (): void {
    ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'room-document-bytes',
            'incoming',
            $this->room['id'],
            '2026-01-02 10:00:00',
            null,
            'room-document.jpg',
        ))
        ->assertCreated();

    ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'property-document-bytes',
            'incoming',
            null,
            '2026-01-03 10:00:00',
            null,
            'property-document.jpg',
        ))
        ->assertCreated();

    $data = ($this->client)()
        ->getJson('/api/v1/properties/' . $this->property['id'] . '/lease-photos')
        ->assertOk()
        ->json('data');

    expect($data['groups'])
        ->toHaveCount(2)
        ->and(collect($data['groups'])->contains(static fn(array $group): bool => $group['room'] === null))
        ->toBeTrue()
        ->and(
            collect($data['groups'])
                ->contains(fn(array $group): bool => ($group['room']['id'] ?? null) === $this->room['id']),
        )
        ->toBeTrue();

    $lease = ($this->client)()
        ->getJson('/api/v1/leases/' . $this->lease['id'])
        ->assertOk()
        ->json('data');

    expect($lease['property']['id'])->toBe($this->property['id'])->and($lease['lease_reference'])->toBe('LEASE-654-1');
});

it('lets an accounting reader in the same organisation read the list but store no copy', function (): void {
    ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'shared-reader-bytes',
            'incoming',
            $this->room['id'],
            '2026-01-02 10:00:00',
            null,
            'shared-reader.jpg',
        ))
        ->assertCreated();

    $users = app(UserWriteFactory::class);
    $reader = $users->create([
        'name' => 'Accounting Reader',
        'email' => 'accounting-reader-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);
    $users->attachToOrganisation($reader, $this->organisation, 'viewer');
    $users->acceptTerms($reader, TermsAcceptanceSource::Register);
    ($this->client)()->postJson('/api/v1/properties/' . $this->property['id'] . '/assignments', [
        'user_id' => $reader->id,
        'role' => 'viewer',
    ])->assertCreated();

    $recordCount = EvidenceRecord::query()->count();
    $objectCount = count($this->storage->objects);

    $response = actingAs($reader)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->getJson('/api/v1/properties/' . $this->property['id'] . '/lease-photos')
        ->assertOk();

    $payload = (string) json_encode($response->json());

    expect($payload)
        ->not
        ->toContain('storage_path')
        ->and($response->json('data.property_id'))
        ->toBe($this->property['id'])
        ->and(EvidenceRecord::query()->count())
        ->toBe($recordCount)
        ->and(count($this->storage->objects))
        ->toBe($objectCount)
        ->and(EvidenceLink::query()->count())
        ->toBe(1);
});

it('does not let another organisation list or fetch lease photos', function (): void {
    $recordId = ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'tenant-private-bytes',
            'incoming',
            $this->room['id'],
            '2026-01-02 10:00:00',
            null,
            'tenant-private.jpg',
        ))
        ->assertCreated()
        ->json('data.id');

    ['organisation' => $otherOrganisation, 'user' => $otherUser] = tenantUser('lease-photos-other');
    $otherClient = fn() => actingAs($otherUser)->withHeader('X-Organisation-Id', (string) $otherOrganisation->id);

    $otherClient()->getJson('/api/v1/properties/' . $this->property['id'] . '/lease-photos')->assertNotFound();

    $otherClient()->getJson('/api/v1/evidence/' . $recordId)->assertNotFound();

    $otherClient()->getJson('/api/v1/leases/' . $this->lease['id'])->assertNotFound();
});

it('never silently replaces evidence: a corrected photo is a new record linked to the old', function (): void {
    $firstResponse = ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'original-bytes',
            'incoming',
            $this->room['id'],
            '2026-01-02 10:00:00',
            null,
            'original.jpg',
        ))
        ->assertCreated();

    $firstRecordId = $firstResponse->json('data.id');
    $firstRecord = EvidenceRecord::query()->findOrFail($firstRecordId);
    $firstLink = EvidenceLink::query()->where('evidence_record_id', $firstRecordId)->sole();

    $secondResponse = ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'corrected-bytes',
            'incoming',
            $this->room['id'],
            '2026-01-02 10:00:00',
            $firstLink->id,
            'corrected.jpg',
        ))
        ->assertCreated();

    $secondRecordId = $secondResponse->json('data.id');
    $secondRecord = EvidenceRecord::query()->findOrFail($secondRecordId);
    $secondLink = EvidenceLink::query()->where('evidence_record_id', $secondRecordId)->sole();

    $groups = ($this->client)()
        ->getJson('/api/v1/properties/' . $this->property['id'] . '/lease-photos')
        ->assertOk()
        ->json('data.groups');

    $photoIds = collect($groups)
        ->flatMap(static fn(array $group): array => $group['photos'])
        ->pluck('id')
        ->all();

    expect(EvidenceRecord::query()->count())
        ->toBe(2)
        ->and($secondRecord->sha256_hash)
        ->not
        ->toBe($firstRecord->sha256_hash)
        ->and($firstRecord->fresh()->sha256_hash)
        ->toBe($firstRecord->sha256_hash)
        ->and($firstLink->fresh()->replaces_link_id)
        ->toBeNull()
        ->and($secondLink->replaces_link_id)
        ->toBe($firstLink->id)
        ->and($photoIds)
        ->toContain($firstLink->id)
        ->and($photoIds)
        ->toContain($secondLink->id);
});

it('rejects a lease upload without a phase or with a room from another property', function (): void {
    $settings = $this->organisation->settings;
    $settings['plan'] = 'starter';
    $this->organisation->settings = $settings;
    $this->organisation->save();

    ($this->client)()
        ->postJson('/api/v1/evidence/upload', [
            'file' => UploadedFile::fake()->createWithContent('no-phase.jpg', 'no-phase-bytes'),
            'attachable_type' => 'lease',
            'attachable_id' => $this->lease['id'],
            'area_room_id' => $this->room['id'],
        ])
        ->assertUnprocessable();

    $otherProperty = ($this->client)()
        ->postJson('/api/v1/properties', propertyPayload('Other House'))
        ->assertCreated()
        ->json('data');

    $otherRoom = ($this->client)()
        ->postJson('/api/v1/properties/' . $otherProperty['id'] . '/rooms', [
            'name' => 'Bathroom',
            'room_type' => 'bathroom',
            'sort_order' => 1,
        ])
        ->assertCreated()
        ->json('data');

    ($this->client)()
        ->postJson('/api/v1/evidence/upload', leasePhotoPayload(
            $this->lease['id'],
            'wrong-room-bytes',
            'incoming',
            $otherRoom['id'],
            '2026-01-03 09:00:00',
        ))
        ->assertUnprocessable();
});
