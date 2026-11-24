<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\MailAdapterInterface;
use GuzzleHttp\Client;

final class ZekiniMailAdapter implements MailAdapterInterface
{
    private readonly Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => rtrim((string) config('services.mail.url'), '/') . '/',
            'timeout' => 10,
        ]);
    }

    public function send(array $payload): array
    {
        $response = $this->client->post('api/v1/mail', ['json' => $payload]);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
