<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

final class PlanLimitExceeded extends InvalidArgumentException
{
    public function __construct(
        string $message,
        public readonly string $planCode,
        public readonly string $nextPlanCode,
        public readonly int $nextPlanPriceCents,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function upgrade(): array
    {
        return [
            'plan' => $this->nextPlanCode,
            'price_monthly_cents' => $this->nextPlanPriceCents,
        ];
    }
}
