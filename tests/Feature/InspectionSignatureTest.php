<?php

declare(strict_types=1);

use App\Factories\InspectionWriteFactory;
use App\Factories\PropertyWriteFactory;
use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\SigningAccessLog;
use App\Models\SigningToken;
use App\Support\Tenancy\CurrentOrganisation;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function pngSignature(): string
{
    $image = imagecreatetruecolor(120, 40);
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
}

define('PNG_SIGNATURE', pngSignature());

function signingToken(string $url): string
{
    return substr($url, strrpos($url, '/') + 1);
}

beforeEach(function (): void {
    resetTenant();
    ['organisation' => $organisation, 'user' => $user] = tenantUser('alpha');
    $this->organisation = $organisation;
    $this->user = $user;

    app(CurrentOrganisation::class)->set($organisation->id);
    $property = app(PropertyWriteFactory::class)->create(propertyPayload('Main House'));
    $this->inspection = app(InspectionWriteFactory::class)->create($property, 'incoming', null, null);
    $this->inspection->forceFill(['status' => Inspection::STATUS_SUBMITTED, 'submitted_at' => now()])->save();
    resetTenant();

    $this->asOwner = fn() => withHeader('X-Organisation-Id', (string) $organisation->id)->actingAs($user);
});

it('lets the landlord sign in the app and records the timestamp and audit event', function (): void {
    ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/signatures", [
        'party' => 'landlord',
        'signer_name' => 'Lara Landlord',
        'signature' => PNG_SIGNATURE,
    ])->assertCreated()->assertJsonPath('data.signatures.0.party', 'landlord')->assertJsonPath('data.signatures.0.signed_via', 'app')->assertJsonPath('data.signatures.0.signer_name', 'Lara Landlord');

    $audit = AuditLog::query()->where('event', 'signed')->firstOrFail();
    expect($audit->new_values['party'])->toBe('landlord')->and($audit->new_values)->not->toHaveKey('signature_image');
});

it('refuses a second signature from the same party and signing a draft', function (): void {
    $payload = ['party' => 'landlord', 'signer_name' => 'Lara', 'signature' => PNG_SIGNATURE];
    $url = "/api/v1/inspections/{$this->inspection->id}/signatures";

    ($this->asOwner)()->postJson($url, $payload)->assertCreated();
    ($this->asOwner)()->postJson($url, $payload)->assertUnprocessable();

    $draft = Inspection::query()->allOrganisations()->whereKey($this->inspection->id)->first();
    $draft->forceFill(['status' => Inspection::STATUS_DRAFT])->save();

    ($this->asOwner)()->postJson($url, ['party' => 'tenant'] + $payload)->assertUnprocessable();
});

it('rejects a signature that is not a small PNG', function (): void {
    ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/signatures", [
        'party' => 'tenant',
        'signer_name' => 'Tom',
        'signature' => 'data:image/png;base64,' . base64_encode('not a png'),
    ])->assertUnprocessable();
});

it('lets a tenant sign from a link with no account and the link is single use', function (): void {
    $link = ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/signing-links", [
        'party' => 'tenant',
    ])->assertCreated();

    $token = signingToken($link->json('data.url'));

    expect(SigningToken::query()->allOrganisations()->where('token_hash', $token)->exists())->toBeFalse();

    resetTenant();
    $this->app['auth']->forgetGuards();

    getJson("/api/v1/sign/{$token}")
        ->assertOk()
        ->assertJsonPath('data.party', 'tenant')
        ->assertJsonPath('data.property_name', 'Main House');

    postJson("/api/v1/sign/{$token}", [
        'signer_name' => 'Tom Tenant',
        'signature' => PNG_SIGNATURE,
    ])->assertCreated()->assertJsonPath('data.party', 'tenant');

    postJson("/api/v1/sign/{$token}", ['signer_name' => 'Tom Tenant', 'signature' => PNG_SIGNATURE])->assertStatus(410);

    $payload = ($this->asOwner)()->getJson("/api/v1/inspections/{$this->inspection->id}")->assertOk();
    expect($payload->json('data.signatures'))
        ->toHaveCount(1)
        ->and($payload->json('data.signatures.0.signed_via'))
        ->toBe('link')
        ->and($payload->json('data.signatures.0.signed_at'))
        ->not->toBeNull();
});

it('refuses and logs an expired, revoked or unknown link', function (): void {
    $issue = fn(string $party) => signingToken(
        ($this->asOwner)()->postJson("/api/v1/inspections/{$this->inspection->id}/signing-links", [
            'party' => $party,
        ])->json('data.url'),
    );

    $expired = $issue('tenant');
    SigningToken::query()
        ->allOrganisations()
        ->where('token_hash', SigningToken::hash($expired))
        ->update([
            'expires_at' => now()->subMinute(),
        ]);

    $revoked = $issue('landlord');
    ($this->asOwner)()->deleteJson("/api/v1/inspections/{$this->inspection->id}/signing-links", [
        'party' => 'landlord',
    ])->assertOk()->assertJsonPath('data.revoked', 1);

    resetTenant();
    $this->app['auth']->forgetGuards();

    getJson("/api/v1/sign/{$expired}")->assertStatus(410);
    postJson("/api/v1/sign/{$revoked}", ['signer_name' => 'T', 'signature' => PNG_SIGNATURE])->assertStatus(410);
    getJson('/api/v1/sign/does-not-exist')->assertNotFound();

    expect(SigningAccessLog::query()->pluck('outcome')->all())->toBe(['expired', 'revoked', 'unknown']);
    expect(
        Inspection::query()
            ->allOrganisations()
            ->find($this->inspection->id)
            ->signatures()
            ->allOrganisations()
            ->count(),
    )
        ->toBe(0);
});

it('issuing a new link revokes the previous live link for that party', function (): void {
    $url = "/api/v1/inspections/{$this->inspection->id}/signing-links";
    $first = signingToken(($this->asOwner)()->postJson($url, ['party' => 'tenant'])->json('data.url'));
    ($this->asOwner)()->postJson($url, ['party' => 'tenant'])->assertCreated();

    resetTenant();
    $this->app['auth']->forgetGuards();

    getJson("/api/v1/sign/{$first}")->assertStatus(410);
});
