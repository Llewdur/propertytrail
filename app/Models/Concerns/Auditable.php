<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes an immutable audit entry for every significant model lifecycle
 * event. The entry records the actor, the tenant and the old/new attribute
 * snapshots. It never updates an existing entry.
 *
 * @mixin Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(static function (Model $model): void {
            self::recordAudit($model, 'created', [], $model->getRawOriginal() ?: $model->getAttributes());
        });

        static::updated(static function (Model $model): void {
            $new = $model->getAttributes();
            $old = [];

            foreach ($model->getChanges() as $key => $value) {
                $old[$key] = $model->getRawOriginal($key);
            }

            self::recordAudit($model, 'updated', $old, $new);
        });

        static::deleted(static function (Model $model): void {
            self::recordAudit($model, 'deleted', $model->getAttributes(), []);
        });
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function recordAudit(Model $model, string $event, array $old, array $new): void
    {
        if (!in_array($event, ['created', 'updated', 'deleted'], true)) {
            return;
        }

        AuditLog::query()->create([
            'organisation_id' => $model->getAttribute('organisation_id'),
            'actor_user_id' => Auth::id(),
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'event' => $event,
            'old_values' => $old === [] ? null : $old,
            'new_values' => $new === [] ? null : $new,
        ]);
    }
}
