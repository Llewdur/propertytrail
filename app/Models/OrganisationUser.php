<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class OrganisationUser extends Pivot
{
    protected $table = 'organisation_user';

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'user_id',
        'role',
        'permissions',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'permissions' => 'array',
    ];

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
