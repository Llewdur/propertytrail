<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\WhatsAppAdapterInterface;
use GuzzleHttp\Client;

final class ZekiniWhatsAppAdapter implements WhatsAppAdapterInterface
{
    private readonly Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => rtrim((string) config('services.whatsapp.url'), '/') . '/',
            'timeout' => 10,
        ]);
    }

    public function sendMessage(string $recipient, string $message): array
    {
        $response = $this->client->post('api/v1/messages', [
            'json' => ['recipient' => $recipient, 'message' => $message],
        ]);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
