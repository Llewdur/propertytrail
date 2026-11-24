<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Machine bearer token for a fleet service: OAuth2 client_credentials with
 * scope machine:api against the service's own /oauth/token, cached just under
 * the token's one-hour life.
 */
final class FleetServiceToken
{
    private const TTL_SECONDS = 3300;

    public static function for(string $service, ?string $scope = 'machine:api'): string
    {
        return Cache::remember(
            'fleet_token.' . $service . '.' . ($scope ?? 'none'),
            self::TTL_SECONDS,
            static function () use ($service, $scope): string {
                $body = [
                    'grant_type' => 'client_credentials',
                    'client_id' => config("services.{$service}.client_id"),
                    'client_secret' => config("services.{$service}.client_secret"),
                ];

                if ($scope !== null) {
                    $body['scope'] = $scope;
                }

                $response = Http::asForm()
                    ->timeout(10)
                    ->post(rtrim((string) config("services.{$service}.url"), '/') . '/oauth/token', $body);

                $token = $response->successful() ? $response->json('access_token') : null;

                if (!is_string($token) || $token === '') {
                    throw new RuntimeException(
                        "Could not obtain a {$service} access token (HTTP {$response->status()}).",
                    );
                }

                return $token;
            },
        );
    }

    public static function forget(string $service): void
    {
        Cache::forget('fleet_token.' . $service);
    }
}
