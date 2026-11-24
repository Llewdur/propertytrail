<?php

declare(strict_types=1);

namespace App\Support;

final class ConditionVocabulary
{
    public const EXCELLENT = 'excellent';
    public const GOOD = 'good';
    public const FAIR = 'fair';
    public const POOR = 'poor';
    public const DAMAGED = 'damaged';

    /** @var array<string, string> */
    private const LABELS = [
        self::EXCELLENT => 'Excellent',
        self::GOOD => 'Good',
        self::FAIR => 'Fair',
        self::POOR => 'Poor',
        self::DAMAGED => 'Damaged',
    ];

    /** @var array<string, string> */
    private const DISPUTE_SAFE = [
        self::EXCELLENT => 'No visible defect at the time of inspection.',
        self::GOOD => 'Sound condition; minor cosmetic wear only.',
        self::FAIR => 'Wear beyond cosmetic; recorded for the property history.',
        self::POOR => 'Deterioration noted; maintenance advisable.',
        self::DAMAGED => 'Damage noted; supporting evidence recommended.',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(string $key): bool
    {
        return array_key_exists($key, self::LABELS);
    }

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }

    public static function disputeSafe(string $key): ?string
    {
        return self::DISPUTE_SAFE[$key] ?? null;
    }
}
