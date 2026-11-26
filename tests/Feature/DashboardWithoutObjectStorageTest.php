<?php

declare(strict_types=1);

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    resetTenant();

    // A server with no object storage yet: the S3 client cannot even be built.
    config(['filesystems.disks.s3' => [
        'driver' => 's3',
        'key' => null,
        'secret' => null,
        'region' => null,
        'bucket' => null,
    ]]);
});

it('loads every list the dashboard asks for', function (): void {
    ['user' => $user] = tenantUser('no-storage');
    actingAs($user);

    $propertyId = postJson('/api/v1/properties', propertyPayload())->assertCreated()->json('data.id');

    $paths = [
        'health',
        'auth/user',
        'organisations',
        'billing/entitlement',
        'inspections',
        'properties',
        "properties/{$propertyId}/rooms",
        "properties/{$propertyId}/leases",
        "properties/{$propertyId}/lease-photos",
    ];

    foreach ($paths as $path) {
        expect($this->getJson('/api/v1/' . $path)->status())->toBe(200, $path);
    }
});
