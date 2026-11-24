<?php

declare(strict_types=1);

use App\Enums\OrganisationRole;

it('exposes the supported organisation roles', function (): void {
    expect(OrganisationRole::values())->toBe([
        'admin',
        'property_manager',
        'contractor',
        'cleaner',
        'assessor',
        'viewer',
    ]);
});

it('never offers admin as an assignable role', function (): void {
    expect(OrganisationRole::assignableValues())->not->toContain('admin');
});
