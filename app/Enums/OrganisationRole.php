<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganisationRole: string
{
    case Admin = 'admin';
    case PropertyManager = 'property_manager';
    case Contractor = 'contractor';
    case Cleaner = 'cleaner';
    case Assessor = 'assessor';
    case Viewer = 'viewer';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn(self $role): string => $role->value, self::cases());
    }

    /** @return array<int, string> */
    public static function assignableValues(): array
    {
        return array_values(array_filter(
            self::values(),
            static fn(string $value): bool => $value !== self::Admin->value,
        ));
    }
}
