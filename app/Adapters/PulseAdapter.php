<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\PulseAdapterInterface;
use Illuminate\Support\Facades\Log;

final class PulseAdapter implements PulseAdapterInterface
{
    public function track(string $event, array $context = []): void
    {
        if (!config('pulse-sdk.enabled', false)) {
            return;
        }

        Log::channel('pulse')->info($event, $context);
    }
}
