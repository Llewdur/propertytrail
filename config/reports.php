<?php

declare(strict_types=1);

/*
 * Report branding by plan. Plans are configuration, never hardcoded in code
 * (SPEC §19). The pricing model is still undecided, so these are placeholders
 * that can be edited without touching the report pipeline.
 */
return [
    'default_plan' => 'free',

    'watermark_text' => 'PropertyTrail free plan',

    'plans' => [
        'free' => ['watermark' => true, 'custom_logo' => false],
        'starter' => ['watermark' => false, 'custom_logo' => true],
        'landlord' => ['watermark' => false, 'custom_logo' => true],
        'professional' => ['watermark' => false, 'custom_logo' => true],
        'portfolio' => ['watermark' => false, 'custom_logo' => true],
    ],
];
