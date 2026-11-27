<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendPulseAuthEventJob;
use App\Models\AuthEvent;
use Zekini\PulseSdk\Audit\ClientIpResolver;

/** Keeps each sign-in event here first, then sends the same event to Pulse (security-telemetry.md 4.1a). */
final class AuthEventRecorder
{
    public function __construct(
        private readonly ClientIpResolver $ipResolver,
    ) {}

    public function record(string $event, string $outcome, int|string|null $userId = null): void
    {
        // Recording must never stop somebody signing in or out.
        rescue(function () use ($event, $outcome, $userId): void {
            $request = request();
            $userAgent = $request->userAgent();

            $record = AuthEvent::query()->create([
                'event' => $event,
                'outcome' => $outcome,
                'user_id' => $userId,
                'ip' => $this->ipResolver->resolve($request),
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
                'occurred_at' => now(),
            ]);

            if (filled(config('pulse-sdk.audit.write_key'))) {
                SendPulseAuthEventJob::dispatch($record->id)->afterCommit();
            }
        });
    }
}
