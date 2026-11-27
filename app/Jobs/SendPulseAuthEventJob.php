<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AuthEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/** The key goes in a header, never in the URL. */
final class SendPulseAuthEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $authEventId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $record = AuthEvent::query()->find($this->authEventId);

        if ($record === null) {
            return;
        }

        $base = rtrim((string) config('pulse-sdk.audit.endpoint'), '/');

        Http::timeout(5)
            ->acceptJson()
            ->withToken((string) config('pulse-sdk.audit.write_key'))
            ->post($base . '/api/auth-events', [
                'event' => $record->event,
                'occurred_at' => $record->occurred_at->toIso8601String(),
                'product' => 'propertytrail',
                'user_ref' => $record->user_id === null ? null : (string) $record->user_id,
                'ip' => $record->ip,
                'user_agent' => $record->user_agent,
                'outcome' => $record->outcome,
            ])
            ->throw();
    }
}
