<?php

declare(strict_types=1);

namespace App\Contracts\Adapters;

interface PulseAdapterInterface
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function track(string $event, array $context = []): void;
}
