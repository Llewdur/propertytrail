<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\PulseAdapterInterface;
use App\Jobs\SendPulseEventJob;
use Illuminate\Support\Facades\Auth;

final class PulseAdapter implements PulseAdapterInterface
{
    public function track(string $event, array $context = []): void
    {
        if (!config('pulse-sdk.enabled') || blank(config('pulse-sdk.write_key'))) {
            return;
        }

        // Pulse needs a session or a user; a tenant or signer on a public link has neither.
        $userId = Auth::id();

        $job = new SendPulseEventJob(
            $event,
            $context,
            $userId === null ? 'anonymous' : (string) $userId,
            now()->toIso8601String(),
        );

        // Telemetry must never fail the action it describes.
        rescue(static function () use ($job): void {
            dispatch($job)->afterCommit();
        });
    }
}
