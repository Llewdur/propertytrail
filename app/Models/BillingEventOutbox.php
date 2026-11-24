<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $event_type
 * @property string $external_reference_id
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $next_attempt_at
 */
final class BillingEventOutbox extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'event_type',
        'external_reference_id',
        'payload',
        'status',
        'attempts',
        'last_error',
        'next_attempt_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
        ];
    }
}
