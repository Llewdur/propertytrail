<?php

declare(strict_types=1);

namespace App\Contracts\Adapters;

interface WhatsAppAdapterInterface
{
    /**
     * @return array<string, mixed>
     */
    public function sendMessage(string $idempotencyKey, string $recipient, string $message): array;
}
