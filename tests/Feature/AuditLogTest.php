<?php

declare(strict_types=1);

use App\Models\AuditLog;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn() => resetTenant());

it('records immutable tenant audit entries for property writes', function (): void {
    ['organisation' => $organisation, 'user' => $user] = tenantUser('audit');

    $created = actingAs($user)
        ->withHeader('X-Organisation-Id', (string) $organisation->id)
        ->postJson('/api/v1/properties', propertyPayload('Audited House'))
        ->assertCreated()
        ->json('data');

    $logs = getJson('/api/v1/audit-logs')->assertOk()->assertJsonPath('data.0.event', 'created')->json('data');

    expect($logs)->not->toBeEmpty();
    expect(
        AuditLog::query()
            ->where('auditable_type', \App\Models\Property::class)
            ->exists(),
    )
        ->toBeTrue()
        ->and(AuditLog::query()->where('organisation_id', $organisation->id)->exists())
        ->toBeTrue()
        ->and(AuditLog::query()->where('actor_user_id', $user->id)->exists())
        ->toBeTrue();
});
