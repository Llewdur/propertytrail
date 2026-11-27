<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * How a tenant is kept coming back to one lease and phase's capture: a Fleet Push
 * subscription, or WhatsApp when they could not accept notifications.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $lease_id
 * @property string $phase
 * @property string|null $push_link_id
 * @property string|null $push_opt_in_url
 * @property \Illuminate\Support\Carbon|null $push_link_expires_at
 * @property \Illuminate\Support\Carbon|null $push_subscribed_at
 * @property string|null $push_device_class
 * @property string|null $fallback_recipient
 * @property \Illuminate\Support\Carbon|null $fallback_at
 * @property int $reminders_sent
 * @property \Illuminate\Support\Carbon|null $last_reminded_at
 * @property \Illuminate\Support\Carbon|null $stopped_at
 * @property string|null $stop_reason
 * @property \Illuminate\Support\Carbon $created_at
 */
final class CaptureReminder extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'lease_id',
        'phase',
        'push_link_id',
        'push_opt_in_url',
        'push_link_expires_at',
        'push_subscribed_at',
        'push_device_class',
        'fallback_recipient',
        'fallback_at',
        'reminders_sent',
        'last_reminded_at',
        'stopped_at',
        'stop_reason',
    ];

    /** @var array<array-key, string> */
    protected $hidden = ['push_opt_in_url', 'fallback_recipient'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'push_opt_in_url' => 'encrypted',
            'push_link_expires_at' => 'datetime',
            'push_subscribed_at' => 'datetime',
            'fallback_recipient' => 'encrypted',
            'fallback_at' => 'datetime',
            'reminders_sent' => 'integer',
            'last_reminded_at' => 'datetime',
            'stopped_at' => 'datetime',
        ];
    }

    public function isCleared(): bool
    {
        return $this->push_subscribed_at !== null || $this->fallback_at !== null;
    }

    public function participantReference(): string
    {
        return 'ptr-lease-' . $this->lease_id . '-' . $this->phase;
    }

    public static function findFor(CaptureLink $link): ?self
    {
        return self::query()
            ->allOrganisations()
            ->where('lease_id', $link->lease_id)
            ->where('phase', $link->phase)
            ->first();
    }

    /**
     * Records a step in the tenant's history; never the phone number or the link.
     *
     * @param array<string, mixed> $values
     */
    public function audit(string $event, array $values): void
    {
        AuditLog::query()->create([
            'organisation_id' => $this->organisation_id,
            'actor_user_id' => null,
            'auditable_type' => new Lease()->getMorphClass(),
            'auditable_id' => $this->lease_id,
            'lease_id' => $this->lease_id,
            'event' => $event,
            'new_values' => ['phase' => $this->phase, ...$values],
        ]);
    }
}
