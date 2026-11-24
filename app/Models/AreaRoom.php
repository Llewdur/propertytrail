<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property int $sort_order
 */
final class AreaRoom extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use HasFactory;
    use OrganisationScoped;

    protected $table = 'areas_rooms';

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'property_id',
        'name',
        'room_type',
        'sort_order',
        'notes',
    ];

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
