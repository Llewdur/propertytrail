<?php

declare(strict_types=1);

use App\Factories\OrganisationWriteFactory;
use App\Factories\UserWriteFactory;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganisation;
use Tests\TestCase;
use Zekini\Auth\Enums\TermsAcceptanceSource;

uses(TestCase::class)->in('Feature');

/** @return array{organisation: Organisation, user: User} */
function tenantUser(string $suffix = 'alpha'): array
{
    $organisation = app(OrganisationWriteFactory::class)->create([
        'name' => 'Organisation ' . ucfirst($suffix),
        'currency' => 'ZAR',
        'timezone' => 'Africa/Johannesburg',
    ]);

    $users = app(UserWriteFactory::class);
    $user = $users->create([
        'name' => 'User ' . ucfirst($suffix),
        'email' => 'user-' . $suffix . '-' . uniqid() . '@example.test',
        'password' => 'password',
    ]);

    $users->attachToOrganisation($user, $organisation, 'admin');
    $users->acceptTerms($user, TermsAcceptanceSource::Register);

    return ['organisation' => $organisation, 'user' => $user];
}

/** @return array<string, string> */
function propertyPayload(string $name = 'Main House'): array
{
    return [
        'name' => $name,
        'reference_code' => 'REF-' . strtoupper(uniqid()),
        'property_type' => 'residential_long',
        'address_line_1' => '1 Main Road',
        'city' => 'Cape Town',
        'province' => 'WC',
        'postal_code' => '8001',
        'gps_latitude' => '-33.9248700',
        'gps_longitude' => '18.4240600',
        'status' => 'active',
    ];
}

function resetTenant(): void
{
    app(CurrentOrganisation::class)->clear();
}
