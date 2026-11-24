<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Organisation;

final class ReportPlan
{
    /** @return array{watermark: bool, custom_logo: bool} */
    public static function rulesFor(?Organisation $organisation): array
    {
        $plans = (array) config('reports.plans');
        $default = (string) config('reports.default_plan');
        $plan = $organisation?->settings['plan'] ?? $default;
        $rules = $plans[is_string($plan) ? $plan : $default] ?? $plans[$default] ?? [];

        return [
            'watermark' => (bool) ($rules['watermark'] ?? true),
            'custom_logo' => (bool) ($rules['custom_logo'] ?? false),
        ];
    }
}
