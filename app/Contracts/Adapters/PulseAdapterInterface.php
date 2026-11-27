<?php

declare(strict_types=1);

namespace App\Contracts\Adapters;

interface PulseAdapterInterface
{
    /**
     * Context holds ids and fixed words only, never names, addresses or tokens.
     *
     * @param  array<string, mixed>  $context
     */
    public function track(string $event, array $context = []): void;
}
