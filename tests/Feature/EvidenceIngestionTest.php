<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('evidence');

    $this->storage = new FakeStorageAdapter();
    app()->instance(StorageAdapterInterface::class, $this->storage);

    $this->property = actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson('/api/v1/properties', propertyPayload('Evidence House'))
        ->assertCreated()
        ->json('data');
});

it('uploads evidence to R2 staging with a SHA-256 hash and link', function (): void {
    $file = UploadedFile::fake()->create('photo.jpg', 12, 'image/jpeg');

    $response = actingAs($this->user)
        ->withHeader('X-Organisation-Id', (string) $this->organisation->id)
        ->postJson('/api/v1/evidence/upload', [
            'file' => $file,
            'attachable_type' => 'property',
            'attachable_id' => $this->property['id'],
            'label' => 'Front facade',
        ])
        ->assertCreated();

    $record = EvidenceRecord::query()->findOrFail($response->json('data.id'));
    $path = $record->storage_path;

    expect($record->sha256_hash)
        ->toBe(hash('sha256', file_get_contents($file->getRealPath())))
        ->and($path)
        ->toStartWith("staging/organisations/{$this->organisation->id}/evidence/")
        ->and($this->storage->exists($path))
        ->toBeTrue()
        ->and($this->storage->get($path))
        ->toBe(file_get_contents($file->getRealPath()))
        ->and(EvidenceLink::query()->where('evidence_record_id', $record->id)->count())
        ->toBe(1);
});

it('reuses an evidence record when the same file is uploaded twice', function (): void {
    $contents = str_repeat('identical-image-bytes', 10);
    $first = UploadedFile::fake()->createWithContent('first.jpg', $contents);
    $second = UploadedFile::fake()->createWithContent('second.jpg', $contents);

    $client = fn() => actingAs($this->user)->withHeader('X-Organisation-Id', (string) $this->organisation->id);

    $firstId = $client()
        ->postJson('/api/v1/evidence/upload', [
            'file' => $first,
            'attachable_type' => 'property',
            'attachable_id' => $this->property['id'],
        ])
        ->assertCreated()
        ->json('data.id');

    $secondId = $client()
        ->postJson('/api/v1/evidence/upload', [
            'file' => $second,
            'attachable_type' => 'property',
            'attachable_id' => $this->property['id'],
            'label' => 'Duplicate',
        ])
        ->assertCreated()
        ->json('data.id');

    expect($secondId)
        ->toBe($firstId)
        ->and(EvidenceRecord::query()->count())
        ->toBe(1)
        ->and(EvidenceLink::query()->where('evidence_record_id', $firstId)->count())
        ->toBe(2);
});
