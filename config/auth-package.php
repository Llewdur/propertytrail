<?php

declare(strict_types=1);

return [
    'guard' => env('AUTH_GUARD', 'web'),
    'api_guard' => 'sanctum',
    'prefix' => env('AUTH_ROUTE_PREFIX', 'api/v1/auth'),
    'middleware' => ['audit-api-call', 'web'],
    'protected_middleware' => ['auth:sanctum'],
    'terms_version' => null,
    'terms' => [
        'impersonation_session_key' => env('AUTH_TERMS_IMPERSONATION_KEY', 'impersonating'),
        'impersonation_token_ability' => env('AUTH_TERMS_IMPERSONATION_ABILITY', 'impersonation'),
        'impersonation_token_prefix' => env('AUTH_TERMS_IMPERSONATION_PREFIX', 'impersonation:'),
    ],
    'two_factor' => true,
    'user_relations' => ['organisations'],
    'routes' => [
        'register' => true,
        'login' => true,
        'logout' => true,
        'me' => true,
        'terms' => true,
        'profile' => true,
        'two_factor' => true,
        'trusted_devices' => true,
        'api_tokens' => true,
    ],
    'trusted_devices' => [
        'cookie' => env('AUTH_TRUSTED_DEVICE_COOKIE', 'auth-package_trusted_device'),
        'domain' => env('AUTH_TRUSTED_DEVICE_DOMAIN'),
        'path' => env('AUTH_TRUSTED_DEVICE_PATH', '/'),
        'secure' => env('AUTH_TRUSTED_DEVICE_SECURE'),
        'same_site' => env('AUTH_TRUSTED_DEVICE_SAME_SITE', 'lax'),
        'days' => (int) env('AUTH_TRUSTED_DEVICE_DAYS', 30),
        'max_days' => (int) env('AUTH_TRUSTED_DEVICE_MAX_DAYS', 90),
        'cookie_days' => (int) env('AUTH_TRUSTED_DEVICE_COOKIE_DAYS', 90),
        'purge_expired' => (bool) env('AUTH_TRUSTED_DEVICE_PURGE_EXPIRED', true),
    ],
    'api_session' => [
        'enabled' => (bool) env('AUTH_API_SESSION_ENFORCEMENT', true),
        'open_paths' => ['login', 'logout', 'two-factor/*', 'GET:user', 'api-tokens'],
    ],
    'step_up' => [
        'max_attempts' => (int) env('AUTH_TWO_FACTOR_STEP_UP_MAX_ATTEMPTS', 5),
        'decay_seconds' => (int) env('AUTH_TWO_FACTOR_STEP_UP_DECAY_SECONDS', 60),
        'challenge_ttl' => (int) env('AUTH_TWO_FACTOR_STEP_UP_CHALLENGE_TTL', 120),
    ],
    'credential_issuance' => [
        'password_max_attempts' => (int) env('AUTH_PASSWORD_STEP_UP_MAX_ATTEMPTS', 5),
        'password_decay_seconds' => (int) env('AUTH_PASSWORD_STEP_UP_DECAY_SECONDS', 60),
    ],
];
