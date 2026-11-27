<?php

declare(strict_types=1);

// The package config, changed so that PULSE_API_BASE and PULSE_WRITE_KEY (standards docs/pulse-telemetry.md)
// switch on everything. A PULSE_SDK_* or PULSE_TRACING_* value still overrides its own part.
return [
    /*
     |--------------------------------------------------------------------------
     | Enabled
     |--------------------------------------------------------------------------
     |
     | Master switch. Off by default so a fresh install (no write key yet)
     | never tries to call out to Pulse.
     |
     */

    'enabled' => (bool) env('PULSE_SDK_ENABLED', filled(env('PULSE_SDK_WRITE_KEY') ?: env('PULSE_WRITE_KEY'))),

    /*
     |--------------------------------------------------------------------------
     | Base URL
     |--------------------------------------------------------------------------
     |
     | The Pulse instance receiving errors and logs from this application.
     |
     */

    'base_url' => env('PULSE_SDK_BASE_URL') ?: env('PULSE_API_BASE') ?: 'https://pulse.zekini.co.za',

    /*
     |--------------------------------------------------------------------------
     | Write key
     |--------------------------------------------------------------------------
     |
     | Identifies which Pulse Environment ingested data belongs to. Generated
     | by Pulse when the Environment is created — see Environment::$write_key
     | in the Pulse repo.
     |
     */

    'write_key' => env('PULSE_SDK_WRITE_KEY') ?: env('PULSE_WRITE_KEY'),

    /*
     |--------------------------------------------------------------------------
     | Release version
     |--------------------------------------------------------------------------
     |
     | Tags reported errors/logs with the deploy currently running. It must be a
     | string: the SDK throws on null, which loses the error report.
     |
     */

    'release_version' => (string) (env('PULSE_SDK_RELEASE_VERSION') ?: env('APP_COMMIT')),

    /*
     |--------------------------------------------------------------------------
     | HTTP timeout (seconds)
     |--------------------------------------------------------------------------
     |
     | Kept short — reporting a failure must never be the thing that makes a
     | request slow.
     |
     */

    'timeout' => (float) env('PULSE_SDK_TIMEOUT', 2.0),

    /*
     |--------------------------------------------------------------------------
     | Fleet API audit trail (standards docs/api-audit-trail.md)
     |--------------------------------------------------------------------------
     |
     | Records every inbound API call and webhook (route middleware
     | `audit-api-call` / `audit-api-call:webhook`), every outbound Http call and
     | every AI call (ApiCallRecorder::recordAiCall) as a metadata-only row sent
     | to Pulse. No bodies, headers, query values or credentials, ever. Inert
     | until both the endpoint and the write key are set.
     |
     */

    'audit' => [
        // Max rows held in memory per process before new rows are dropped (and counted).
        'buffer_size' => (int) env('PULSE_API_AUDIT_BUFFER_SIZE', 200),

        // Real client IP: CF-Connecting-IP is trusted only from these ranges.
        'cloudflare_ranges' => [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ],

        'enabled' => (bool) env('PULSE_API_AUDIT_ENABLED', true),

        // The Pulse base URL, e.g. https://pulse.zekini.co.za
        'endpoint' => env('PULSE_TRACING_ENDPOINT') ?: env('PULSE_API_BASE') ?: 'https://pulse.zekini.co.za',

        // Timeout for shipping a batch to Pulse; the host never waits longer.
        'ship_timeout_seconds' => (int) env('PULSE_API_AUDIT_SHIP_TIMEOUT', 2),

        // The Environment write key; sent as `Authorization: Bearer`, never in the URL.
        'write_key' => env('PULSE_TRACING_WRITE_KEY') ?: env('PULSE_WRITE_KEY'),
    ],
];
