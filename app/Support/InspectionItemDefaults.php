<?php

declare(strict_types=1);

namespace App\Support;

final class InspectionItemDefaults
{
    /** @var array<string, list<string>> */
    private const ITEMS = [
        'dining' => ['Walls', 'Floors', 'Windows', 'Ceiling', 'Lights'],
        'outdoor' => ['Walls', 'Grounds', 'Boundary', 'Lights'],
        'other' => ['Walls', 'Floors', 'Lights'],
        'living' => ['Walls', 'Floors', 'Windows', 'Ceiling', 'Lights'],
        'kitchen' => ['Walls', 'Floors', 'Counters', 'Cupboards', 'Sink', 'Appliances', 'Lights'],
        'bedroom' => ['Walls', 'Floors', 'Windows', 'Cupboards', 'Lights'],
        'bathroom' => ['Walls', 'Floors', 'Toilet', 'Basin', 'Shower or bath', 'Tiles', 'Lights'],
        'garage' => ['Walls', 'Floors', 'Door', 'Lights'],
    ];

    /** @return list<string> */
    public static function forRoomType(string $roomType): array
    {
        return self::ITEMS[$roomType] ?? self::ITEMS['living'];
    }

    /** @return list<string> */
    public static function roomTypes(): array
    {
        return array_keys(self::ITEMS);
    }
}
