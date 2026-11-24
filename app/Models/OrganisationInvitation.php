<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $email
 * @property string $role
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 */
final class OrganisationInvitation extends Model
{
    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'email',
        'role',
        'token_hash',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    /** @var array<array-key, string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }
}
