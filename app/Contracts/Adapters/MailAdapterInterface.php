<?php

declare(strict_types=1);

namespace App\Contracts\Adapters;

interface MailAdapterInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function send(array $payload): array;
}
