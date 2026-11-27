<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class CaptureTime
{
    /** The phone's own time, so what was queued offline keeps its order. A clock running ahead is ignored. */
    public static function from(mixed $value): CarbonInterface
    {
        if (!is_string($value) || $value === '') {
            return now();
        }

        $takenAt = Carbon::parse($value)->setTimezone((string) config('app.timezone'));

        return $takenAt->isFuture() ? now() : $takenAt;
    }
}
