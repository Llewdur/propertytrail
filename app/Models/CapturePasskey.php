<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * The phone unlock (WebAuthn passkey) a tenant registered for one lease and phase.
 * It proves someone who can unlock that phone came back, not that they are the
 * named tenant; the browser never hands over face or fingerprint data. Audited by
 * CapturePasskeyService rather than per row, as every unlock rewrites the counter.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $lease_id
 * @property string $phase
 * @property string $credential_id
 * @property array<string, mixed> $credential
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class CapturePasskey extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'lease_id',
        'phase',
        'credential_id',
        'credential',
        'last_used_at',
    ];

    /** @var array<array-key, string> */
    protected $hidden = ['credential'];

    /** Records a registration or reset; the credential itself is never written to the log. */
    public function audit(string $event, ?int $userId): void
    {
        AuditLog::query()->create([
            'organisation_id' => $this->organisation_id,
            'actor_user_id' => $userId,
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'event' => $event,
            'new_values' => ['lease_id' => $this->lease_id, 'phase' => $this->phase],
        ]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'credential' => 'array',
            'last_used_at' => 'datetime',
        ];
    }
}
