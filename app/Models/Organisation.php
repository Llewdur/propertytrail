<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property array<string, mixed>|null $settings
 * @property string|null $accounting_customer_id
 */
final class Organisation extends Model
{
    use HasFactory;

    /** @var array<int, string> */
    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'status',
        'currency',
        'timezone',
        'settings',
        'accounting_customer_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'settings' => 'array',
    ];

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this
            ->belongsToMany(User::class, 'organisation_user')
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }
}
