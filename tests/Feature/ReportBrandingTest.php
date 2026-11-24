<?php

declare(strict_types=1);

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Services\ReportBrandingService;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeStorageAdapter;

use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();
    $this->storage = new FakeStorageAdapter();
    app()->instance(StorageAdapterInterface::class, $this->storage);

    ['organisation' => $this->organisation, 'user' => $user] = tenantUser('alpha');
    $this->asOwner = fn() => withHeader('X-Organisation-Id', (string) $this->organisation->id)->actingAs($user);
    $this->logo = fn(string $name = 'logo.png') => UploadedFile::fake()->image($name, 80, 30);
});

it('stores an uploaded logo through the storage adapter and records it in settings', function (): void {
    ($this->asOwner)()
        ->post(
            "/api/v1/organisations/{$this->organisation->id}/logo",
            ['logo' => ($this->logo)()],
            ['Accept' => 'application/json'],
        )
        ->assertOk();

    expect($this->storage->objects)
        ->toHaveKey("branding/{$this->organisation->id}/logo.png")
        ->and($this->organisation->refresh()->settings['branding']['logo_path'])
        ->toBe("branding/{$this->organisation->id}/logo.png");
});

it('rejects a non-image logo', function (): void {
    ($this->asOwner)()
        ->post(
            "/api/v1/organisations/{$this->organisation->id}/logo",
            ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')],
            ['Accept' => 'application/json'],
        )
        ->assertUnprocessable();

    expect($this->storage->objects)->toBe([]);
});

it('stamps a free-plan report with the watermark and no custom logo', function (): void {
    ($this->asOwner)()->post(
        "/api/v1/organisations/{$this->organisation->id}/logo",
        ['logo' => ($this->logo)()],
        ['Accept' => 'application/json'],
    );

    $branding = app(ReportBrandingService::class)->forOrganisation($this->organisation->refresh());

    expect($branding['watermark'])->toBe(config('reports.watermark_text'))->and($branding['logo_data_uri'])->toBeNull();
});

it('puts the logo on a paid-plan report and removes the watermark', function (): void {
    ($this->asOwner)()->post(
        "/api/v1/organisations/{$this->organisation->id}/logo",
        ['logo' => ($this->logo)()],
        ['Accept' => 'application/json'],
    );
    $this->organisation->forceFill(['settings' => [
        ...$this->organisation->refresh()->settings,
        'plan' => 'professional',
    ]])->save();

    $branding = app(ReportBrandingService::class)->forOrganisation($this->organisation->refresh());

    expect($branding['watermark'])->toBeNull()->and($branding['logo_data_uri'])->toStartWith('data:image/png;base64,');
});

it('does not let an organisation edit its own plan or branding through settings', function (): void {
    ($this->asOwner)()->putJson("/api/v1/organisations/{$this->organisation->id}", ['settings' => [
        'plan' => 'portfolio',
    ]])->assertUnprocessable();
});

it('does not let a new organisation pick its own plan or branding', function (): void {
    ($this->asOwner)()
        ->postJson('/api/v1/organisations', ['name' => 'Sneaky', 'settings' => ['plan' => 'portfolio']])
        ->assertUnprocessable();
});

it('ignores a logo path outside the organisation own prefix', function (): void {
    $this->storage->put('reports/9/9/v1.pdf', 'secret');
    $this->organisation->forceFill([
        'settings' => [
            'plan' => 'starter',
            'branding' => ['logo_path' => 'reports/9/9/v1.pdf', 'logo_mime' => 'image/png'],
        ],
    ])->save();

    expect(
        app(ReportBrandingService::class)->forOrganisation($this->organisation->refresh())['logo_data_uri'],
    )->toBeNull();
});

it('keeps plan and branding when other settings are updated', function (): void {
    $this->organisation->forceFill(['settings' => ['plan' => 'starter']])->save();

    ($this->asOwner)()->putJson("/api/v1/organisations/{$this->organisation->id}", ['settings' => [
        'theme' => 'dark',
    ]])->assertOk();

    expect($this->organisation->refresh()->settings)->toEqualCanonicalizing(['theme' => 'dark', 'plan' => 'starter']);
});
