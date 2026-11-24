<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'actor_user_id',
        'auditable_type',
        'auditable_id',
        'event',
        'old_values',
        'new_values',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];
}
