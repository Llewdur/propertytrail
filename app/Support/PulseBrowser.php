<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The Pulse browser SDK tag and the Content-Security-Policy entries it needs (standards docs/pulse-telemetry.md).
 * Nothing is rendered or allowed without a write key.
 */
final class PulseBrowser
{
    /** Paths that carry a secret token. Pulse replaces each `*` before a URL leaves the browser. */
    public const MASKED_PATHS = ['/sign/*', '/r/*', '/api/v1/sign/*', '/api/v1/invitations/*'];

    public static function tags(): string
    {
        if (!self::enabled()) {
            return '';
        }

        return (
            '<script>'
            . self::configScript()
            . '</script><script src="'
            . e(self::base())
            . '/pulse.js" defer></script>'
        );
    }

    /** Adds Pulse to a policy. A hash is added only where inline script is not already allowed, which it would cancel. */
    public static function contentSecurityPolicy(string $policy): string
    {
        if (!self::enabled()) {
            return $policy;
        }

        $directives = [];

        foreach (array_filter(array_map(trim(...), explode(';', $policy))) as $directive) {
            [$name, $value] = array_pad(explode(' ', $directive, 2), 2, '');
            $directives[$name] = $value;
        }

        $script = $directives['script-src'] ?? '';
        $hash = str_contains($script, "'unsafe-inline'")
            ? ''
            : " 'sha256-" . base64_encode(hash('sha256', self::configScript(), true)) . "'";

        $directives['script-src'] = trim($script . ' ' . self::origin() . $hash);
        $directives['connect-src'] = trim(($directives['connect-src'] ?? '') . ' ' . self::origin());

        return implode('; ', array_map(
            static fn(string $name, string $value): string => trim($name . ' ' . $value),
            array_keys($directives),
            $directives,
        ));
    }

    private static function configScript(): string
    {
        return (
            'window.pulseConfig='
            . json_encode(
                [
                    'writeKey' => (string) config('services.pulse.write_key'),
                    'apiBase' => self::base(),
                    'captureClickText' => false,
                    'stripQuery' => true,
                    // The tenant capture link keeps its token in the fragment.
                    'stripFragment' => true,
                    'maskPaths' => self::MASKED_PATHS,
                    'release' => (string) config('app.version'),
                ],
                JSON_THROW_ON_ERROR | JSON_HEX_TAG,
            )
            . ';'
        );
    }

    private static function enabled(): bool
    {
        return filled(config('services.pulse.write_key'));
    }

    private static function base(): string
    {
        return rtrim((string) config('services.pulse.api_base'), '/');
    }

    private static function origin(): string
    {
        $parts = parse_url(self::base());
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $port;
    }
}
