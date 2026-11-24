<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $inspection_id
 * @property int|null $area_room_id
 * @property string $title
 * @property string $room_type
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $saved_at
 * @property-read Collection<int, InspectionItem> $items
 */
final class InspectionSection extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'inspection_id',
        'area_room_id',
        'title',
        'room_type',
        'sort_order',
        'saved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'saved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Inspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /** @return BelongsTo<AreaRoom, $this> */
    public function areaRoom(): BelongsTo
    {
        return $this->belongsTo(AreaRoom::class, 'area_room_id');
    }

    /** @return HasMany<InspectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InspectionItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
