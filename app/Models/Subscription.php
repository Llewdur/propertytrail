<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $plan_code
 * @property string $status
 * @property string|null $external_customer_id
 * @property string|null $external_subscription_id
 */
final class Subscription extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'plan_code',
        'status',
        'external_customer_id',
        'external_subscription_id',
        'starts_at',
        'ends_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
