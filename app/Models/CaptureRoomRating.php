<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's own rating of one room's condition during a capture.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $lease_id
 * @property string $phase
 * @property int|null $area_room_id
 * @property int|null $capture_link_id
 * @property string $recorded_by
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property string $condition
 */
final class CaptureRoomRating extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'lease_id',
        'phase',
        'area_room_id',
        'capture_link_id',
        'recorded_by',
        'recorded_at',
        'condition',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }
}
