<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\AccountingAdapterInterface;
use GuzzleHttp\Client;

final class FleetAccountingAdapter implements AccountingAdapterInterface
{
    private readonly Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => rtrim((string) config('services.accounting.url'), '/') . '/',
            'timeout' => 10,
        ]);
    }

    public function createCustomer(array $payload): array
    {
        $response = $this->client->post('api/v1/customers', ['json' => $payload]);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
