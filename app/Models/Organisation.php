<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
