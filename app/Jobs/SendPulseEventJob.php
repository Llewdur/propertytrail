<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/** One app event for Pulse. A failure is dropped: telemetry is never worth a retry storm. */
final class SendPulseEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  array<string, mixed>  $properties */
    public function __construct(
        public readonly string $name,
        public readonly array $properties,
        public readonly string $userRef,
        public readonly string $occurredAt,
    ) {}

    public function handle(): void
    {
        $base = rtrim((string) config('pulse-sdk.base_url'), '/');
        $writeKey = (string) config('pulse-sdk.write_key');

        Http::timeout(5)
            ->acceptJson()
            ->post($base . '/api/environments/' . $writeKey . '/events', [
                'name' => $this->name,
                'properties' => $this->properties,
                'external_user_id' => $this->userRef,
                'occurred_at' => $this->occurredAt,
            ])
            ->throw();
    }
}
