<?php

declare(strict_types=1);

namespace App\Contracts\Adapters;

interface AccountingAdapterInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCustomer(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function recordEvent(string $eventType, string $externalReferenceId, array $payload): array;
}
