<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a tenant declares when signing off their capture. The wording is versioned and
 * stored with every sign-off, so a later change never rewrites what someone agreed to.
 * Draft wording: to be approved by the owner and checked against ECTA before real use.
 */
final class CaptureDeclarations
{
    public const VERSION = 'draft-2026-11-27';

    public const IDENTITY = 'identity';

    public const ACCURACY = 'accuracy';

    /** @return list<array{key: string, text: string}> */
    public static function for(string $tenantName): array
    {
        $name = trim($tenantName) === '' ? 'the tenant named on this lease' : trim($tenantName);

        return [
            [
                'key' => self::IDENTITY,
                'text' => "I am {$name}, the tenant on this lease, and I am signing this myself.",
            ],
            [
                'key' => self::ACCURACY,
                'text' =>
                    'This record of the property\'s condition is accurate and complete to the best of my knowledge, '
                        . 'and I agree to sign it electronically.',
            ],
        ];
    }
}
