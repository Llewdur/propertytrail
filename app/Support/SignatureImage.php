<?php

declare(strict_types=1);

namespace App\Support;

use ErrorException;
use InvalidArgumentException;
use SensitiveParameter;

/** Validates a drawn signature: a real, non-trivial PNG data URL under 256 KB. */
final class SignatureImage
{
    public const MAX_BYTES = 262_144;

    private const PREFIX = 'data:image/png;base64,';
    private const MESSAGE = 'The signature must be a PNG image under 256 KB.';

    public static function assertValid(#[SensitiveParameter] string $image): void
    {
        if (!str_starts_with($image, self::PREFIX) || strlen($image) > self::MAX_BYTES) {
            throw new InvalidArgumentException(self::MESSAGE);
        }

        $binary = base64_decode(substr($image, strlen(self::PREFIX)), true);

        if ($binary === false || !str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw new InvalidArgumentException(self::MESSAGE);
        }

        try {
            $info = getimagesizefromstring($binary);
        } catch (ErrorException) {
            $info = false;
        }

        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] < 50 || $info[1] < 20) {
            throw new InvalidArgumentException(self::MESSAGE);
        }
    }
}
