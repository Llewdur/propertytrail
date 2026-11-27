<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's account-less access to capture photos for one lease and phase.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $lease_id
 * @property string $phase
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 */
final class CaptureLink extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const PHASES = ['incoming', 'interim', 'outgoing'];

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'lease_id',
        'phase',
        'token_hash',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'created_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public static function hash(#[\SensitiveParameter] string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
