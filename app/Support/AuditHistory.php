<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Str;

/**
 * Shapes audit entries into a person's history. Secret tokens, credentials,
 * image contents, storage locations and device details never leave here.
 */
final class AuditHistory
{
    /** @var array<int, string> */
    private const array HIDDEN_KEYS = [
        'signature',
        'signature_image',
        'storage_path',
        'storage_driver',
        'content',
        'image',
        'ip',
        'signer_ip',
        'user_agent',
    ];

    /** @var array<int, string> */
    private const array HIDDEN_FRAGMENTS = ['token', 'password', 'secret', 'credential', 'challenge'];

    /**
     * @return array{
     *     id: int,
     *     event: string,
     *     actor: string,
     *     actor_user_id: int|null,
     *     subject_type: string,
     *     subject_id: int,
     *     lease_id: int|null,
     *     old_values: array<array-key, mixed>|null,
     *     new_values: array<array-key, mixed>|null,
     *     occurred_at: string|null
     * }
     */
    public static function entry(AuditLog $log): array
    {
        /** @var array<array-key, mixed>|null $old */
        $old = $log->old_values;
        /** @var array<array-key, mixed>|null $new */
        $new = $log->new_values;

        return [
            'id' => (int) $log->id,
            'event' => (string) $log->event,
            'actor' => $log->actor_user_id === null ? 'tenant_or_system' : 'staff',
            'actor_user_id' => $log->actor_user_id === null ? null : (int) $log->actor_user_id,
            'subject_type' => class_basename((string) $log->auditable_type),
            'subject_id' => (int) $log->auditable_id,
            'lease_id' => $log->lease_id === null ? null : (int) $log->lease_id,
            'old_values' => $old === null ? null : self::redact($old),
            'new_values' => $new === null ? null : self::redact($new),
            'occurred_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $values
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $values): array
    {
        $kept = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && self::hidden($key)) {
                continue;
            }

            $kept[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $kept;
    }

    private static function hidden(string $key): bool
    {
        $key = Str::lower($key);

        return in_array($key, self::HIDDEN_KEYS, true) || Str::contains($key, self::HIDDEN_FRAGMENTS);
    }
}
