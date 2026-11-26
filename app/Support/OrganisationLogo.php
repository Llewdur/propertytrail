<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organisation;

final class OrganisationLogo
{
    /** @var array<string, string> */
    public const EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    public static function pathFor(Organisation $organisation, string $mime): string
    {
        return sprintf('branding/%d/logo.%s', $organisation->id, self::EXTENSIONS[$mime]);
    }

    /** True only for a stored logo that sits under the organisation's own prefix. */
    public static function isOwn(Organisation $organisation, mixed $path, mixed $mime): bool
    {
        return (
            is_string($path)
            && is_string($mime)
            && array_key_exists($mime, self::EXTENSIONS)
            && str_starts_with($path, 'branding/' . $organisation->id . '/')
        );
    }
}
