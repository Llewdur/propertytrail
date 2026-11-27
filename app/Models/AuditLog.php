<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One immutable audit entry. `lease_id` ties an entry to the person on the
 * tenancy, so a tenant's history survives a new link or a new phone.
 *
 * @property int $id
 * @property int|null $organisation_id
 * @property int|null $actor_user_id
 * @property string $auditable_type
 * @property int $auditable_id
 * @property int|null $lease_id
 * @property string $event
 * @property array<array-key, mixed>|null $old_values
 * @property array<array-key, mixed>|null $new_values
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'actor_user_id',
        'auditable_type',
        'auditable_id',
        'lease_id',
        'event',
        'old_values',
        'new_values',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(static function (AuditLog $log): void {
            $log->lease_id ??= self::leaseIdFor(
                (string) $log->auditable_type,
                (int) $log->auditable_id,
                $log->new_values ?? [],
                $log->old_values ?? [],
            );
        });

        static::updating(static function (): never {
            throw new LogicException('Audit entries cannot be edited.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit entries cannot be deleted.');
        });
    }

    /**
     * The tenancy an entry is about: the lease itself, or the lease the
     * recorded record belongs to or is attached to.
     *
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     */
    public static function leaseIdFor(string $auditableType, int $auditableId, array $new, array $old): ?int
    {
        $leaseType = new Lease()->getMorphClass();

        if ($auditableType === $leaseType) {
            return $auditableId;
        }

        foreach ([$new, $old] as $values) {
            if (is_numeric($values['lease_id'] ?? null)) {
                return (int) $values['lease_id'];
            }

            if (($values['attachable_type'] ?? null) === $leaseType && is_numeric($values['attachable_id'] ?? null)) {
                return (int) $values['attachable_id'];
            }
        }

        return null;
    }
}
