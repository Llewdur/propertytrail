<?php

declare(strict_types=1);

namespace App\Support;

use GdImage;
use RuntimeException;

/**
 * Decodes and re-encodes an image, which drops EXIF, GPS, device and capture time.
 * The camera's orientation is applied first, so the copy still stands upright.
 */
final class MetadataFreeJpeg
{
    /** Long edge of a copy, in pixels. */
    public const MAX_EDGE = 2400;

    /** @throws RuntimeException when the bytes are not an image GD can read */
    public static function from(string $bytes): string
    {
        $image = self::quietly(static fn(): GdImage|false => imagecreatefromstring($bytes));

        if (!$image instanceof GdImage) {
            throw new RuntimeException('The photo cannot be read.');
        }

        $image = self::bounded(self::upright($image, $bytes));

        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    private static function upright(GdImage $image, string $bytes): GdImage
    {
        if (!function_exists('exif_read_data') || !str_starts_with($bytes, "\xFF\xD8")) {
            return $image;
        }

        $exif = self::quietly(static fn(): array|false => exif_read_data(
            'data://image/jpeg;base64,' . base64_encode($bytes),
        ));

        $angle = match (is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        $rotated = $angle === 0 ? false : imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }

    private static function bounded(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $longest = max($width, imagesy($image));
        $scaled = $longest <= self::MAX_EDGE
            ? false
            : imagescale($image, (int) round(($width * self::MAX_EDGE) / $longest));

        return $scaled === false ? $image : $scaled;
    }

    /**
     * Runs a GD or EXIF call that warns on a malformed file instead of failing.
     *
     * @template T
     * @param callable(): T $call
     * @return T
     */
    private static function quietly(callable $call): mixed
    {
        set_error_handler(static fn(): bool => true);

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
