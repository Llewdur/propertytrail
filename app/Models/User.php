<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganisationRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 */
#[Fillable([
    'uuid',
    'name',
    'email',
    'password',
    'avatar',
    'google_id',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'two_factor_confirmed_at',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Organisation, $this, OrganisationUser>
     */
    public function organisations(): BelongsToMany
    {
        return $this
            ->belongsToMany(Organisation::class, 'organisation_user')
            ->using(OrganisationUser::class)
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    public function organisationIds(): \Illuminate\Support\Collection
    {
        return $this
            ->organisations()
            ->pluck('organisations.id')
            ->map(fn(mixed $id): int => (int) $id);
    }

    /** @return BelongsToMany<Property, $this> */
    public function assignedProperties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'property_user')->withPivot(['role'])->withTimestamps();
    }

    public function roleIn(Organisation|int $organisation): ?string
    {
        $organisationId = $organisation instanceof Organisation ? $organisation->getKey() : $organisation;
        $pivot = $this->organisations()->whereKey($organisationId)->first()?->getAttribute('pivot');

        return $pivot?->getAttribute('role');
    }

    public function isAdminIn(Organisation|int $organisation): bool
    {
        return $this->roleIn($organisation) === OrganisationRole::Admin->value;
    }
}
