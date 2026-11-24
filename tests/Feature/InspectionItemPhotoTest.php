<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Factories\AreaRoomWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\InspectionItem;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\actingAs;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function photoClient(): mixed
{
    return actingAs(test()->user)->withHeader('X-Organisation-Id', (string) test()->organisation->id);
}

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $this->organisation, 'user' => $this->user] = tenantUser('photos');
    app()->instance(StorageAdapterInterface::class, new FakeStorageAdapter());

    app(CurrentOrganisation::class)->set($this->organisation->id);
    $property = app(PropertyWriteFactory::class)->create(propertyPayload('Photo House'));
    app(AreaRoomWriteFactory::class)->create([
        'organisation_id' => $property->organisation_id,
        'property_id' => $property->id,
        'name' => 'Kitchen',
        'room_type' => 'kitchen',
    ]);
    resetTenant();

    $this->inspection = photoClient()
        ->postJson('/api/v1/inspections', ['property_id' => $property->id, 'phase' => 'incoming'])
        ->assertCreated()
        ->json('data');
    $this->itemId = $this->inspection['sections'][0]['items'][0]['id'];
});

function uploadItemPhoto(int $itemId, string $bytes, array $extra = []): \Illuminate\Testing\TestResponse
{
    return photoClient()->postJson('/api/v1/evidence/upload', [
        'file' => UploadedFile::fake()->createWithContent('cupboard.jpg', $bytes),
        'attachable_type' => 'inspection_item',
        'attachable_id' => $itemId,
        'label' => 'Cupboard door',
    ] + $extra);
}

it('attaches a captioned photo to an inspection item and lists it on the inspection', function (): void {
    uploadItemPhoto($this->itemId, 'first-photo')->assertCreated();

    $photos = photoClient()
        ->getJson('/api/v1/inspections/' . $this->inspection['id'])
        ->assertOk()
        ->json('data.sections.0.items.0.photos');

    expect($photos)
        ->toHaveCount(1)
        ->and($photos[0]['caption'])
        ->toBe('Cupboard door')
        ->and($photos[0]['superseded'])
        ->toBeFalse();
});

it('keeps the original when a photo is retaken and marks it superseded', function (): void {
    uploadItemPhoto($this->itemId, 'original-photo')->assertCreated();
    $originalLink = EvidenceLink::query()->firstOrFail();

    uploadItemPhoto($this->itemId, 'retaken-photo', ['replaces_link_id' => $originalLink->id])->assertCreated();

    $photos = photoClient()
        ->getJson('/api/v1/inspections/' . $this->inspection['id'])
        ->json('data.sections.0.items.0.photos');

    expect(EvidenceRecord::query()->count())
        ->toBe(2)
        ->and($photos)
        ->toHaveCount(2)
        ->and($photos[0]['superseded'])
        ->toBeTrue()
        ->and($photos[1]['superseded'])
        ->toBeFalse()
        ->and($photos[1]['replaces_link_id'])
        ->toBe($originalLink->id);
});

it('refuses a replacement link that belongs to another item', function (): void {
    $otherItem = InspectionItem::query()->where('id', '!=', $this->itemId)->firstOrFail();
    uploadItemPhoto($otherItem->id, 'other-photo')->assertCreated();
    $otherLink = EvidenceLink::query()->firstOrFail();

    uploadItemPhoto($this->itemId, 'mine', ['replaces_link_id' => $otherLink->id])->assertUnprocessable();
});

it('refuses to replace a photo that was already replaced', function (): void {
    uploadItemPhoto($this->itemId, 'original')->assertCreated();
    $originalLink = EvidenceLink::query()->firstOrFail();
    uploadItemPhoto($this->itemId, 'retake-one', ['replaces_link_id' => $originalLink->id])->assertCreated();

    uploadItemPhoto($this->itemId, 'retake-two', ['replaces_link_id' => $originalLink->id])->assertUnprocessable();
});

it('refuses photos on a submitted inspection', function (): void {
    \App\Models\Inspection::query()->whereKey($this->inspection['id'])->update(['status' => 'submitted']);

    uploadItemPhoto($this->itemId, 'late-photo')->assertUnprocessable();
});

it('downloads the stored file', function (): void {
    $evidenceId = uploadItemPhoto($this->itemId, 'download-me')->json('data.id');

    $response = photoClient()->get("/api/v1/evidence/{$evidenceId}/download")->assertOk();

    expect($response->getContent())
        ->toBe('download-me')
        ->and($response->headers->get('Content-Disposition'))
        ->toContain('cupboard.jpg');
});
