<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string|null $label
 * @property string|null $phase
 * @property int|null $area_room_id
 * @property int|null $replaces_link_id
 * @property \Illuminate\Support\Carbon|null $taken_at
 * @property-read EvidenceRecord $evidenceRecord
 * @property-read AreaRoom|null $areaRoom
 */
final class EvidenceLink extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use HasFactory;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'evidence_record_id',
        'attachable_type',
        'attachable_id',
        'label',
        'sort_order',
        'phase',
        'area_room_id',
        'taken_at',
        'replaces_link_id',
        'capture_link_id',
        'capture_corner',
        'capture_shot',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EvidenceRecord, $this> */
    public function evidenceRecord(): BelongsTo
    {
        return $this->belongsTo(EvidenceRecord::class);
    }

    /** @return MorphTo */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<AreaRoom, $this> */
    public function areaRoom(): BelongsTo
    {
        return $this->belongsTo(AreaRoom::class);
    }
}
