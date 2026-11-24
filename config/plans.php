<?php

declare(strict_types=1);

/*
 * Decided pricing model (PTR-15, docs/SPEC.md section 19): hybrid — a free
 * single-inspection tier plus the property-count paid tiers. Plans are
 * configuration, never hardcoded. PTR-16 owns enforcement and billing.
 */
return [
    'currency' => 'ZAR',

    'default_plan' => 'free',

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price_monthly_cents' => 0,
            'property_limit' => 1,
            'inspection_allowance' => 1,
            'watermark' => true,
            'custom_logo' => false,
        ],
        'starter' => [
            'name' => 'Starter',
            'price_monthly_cents' => 14900,
            'property_limit' => 3,
            'inspection_allowance' => null,
            'watermark' => false,
            'custom_logo' => true,
        ],
        'landlord' => [
            'name' => 'Landlord',
            'price_monthly_cents' => 34900,
            'property_limit' => 10,
            'inspection_allowance' => null,
            'watermark' => false,
            'custom_logo' => true,
        ],
        'professional' => [
            'name' => 'Professional',
            'price_monthly_cents' => 69900,
            'property_limit' => 30,
            'inspection_allowance' => null,
            'watermark' => false,
            'custom_logo' => true,
        ],
        'portfolio' => [
            'name' => 'Portfolio',
            'price_monthly_cents' => 149900,
            'property_limit' => 100,
            'inspection_allowance' => null,
            'watermark' => false,
            'custom_logo' => true,
        ],
    ],

    'counting' => [
        'trigger' => 'inspection_created',
        'includes_deleted_drafts' => true,
        'excludes' => ['rooms', 'photos', 'signatures'],
    ],

    'downgrade' => [
        'deletes_data' => false,
        'keeps_reports_openable' => true,
        'keeps_downloads' => true,
        'keeps_locked_reports_unchanged' => true,
        'keeps_sent_signing_links' => true,
        'stops_new_inspections_past_allowance' => true,
    ],
];
