<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * One outbound message (email or WhatsApp) and its delivery outcome.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $inspection_id
 * @property string $party
 * @property string $kind
 * @property string $channel
 * @property string $recipient
 * @property string $idempotency_key
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
final class Delivery extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const KIND_SIGNING_LINK = 'signing_link';
    public const KIND_LOCKED_REPORT = 'locked_report';

    public const CHANNEL_MAIL = 'mail';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    public const MAX_ATTEMPTS = 3;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'inspection_id',
        'party',
        'kind',
        'channel',
        'recipient',
        'idempotency_key',
        'status',
        'attempts',
        'last_error',
        'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'party' => $this->party,
            'kind' => $this->kind,
            'channel' => $this->channel,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'error' => $this->last_error === null ? null : 'Delivery failed; see the audit log.',
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
