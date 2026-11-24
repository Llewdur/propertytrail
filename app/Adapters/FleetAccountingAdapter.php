<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\AccountingAdapterInterface;
use App\Support\FleetServiceToken;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FleetAccountingAdapter implements AccountingAdapterInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCustomer(array $payload): array
    {
        return $this->post('customers', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordEvent(string $eventType, string $externalReferenceId, array $payload): array
    {
        return $this->post('events', [
            'event_type' => $eventType,
            'external_reference_id' => $externalReferenceId,
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $response = Http::withToken(FleetServiceToken::for('accounting', null))
            ->timeout(10)
            ->acceptJson()
            ->post($this->url('/api/v1/machine/' . $path), $payload);

        if (!$response->successful()) {
            throw new RuntimeException('Fleet Accounting rejected ' . $path . ' (HTTP ' . $response->status() . ').');
        }

        $body = $response->json();
        if (!is_array($body)) {
            return [];
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.accounting.url'), '/') . $path;
    }
}
