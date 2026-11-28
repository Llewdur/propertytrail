<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff-approved copy of an earlier tenancy's photo, shown to the tenant of a later
 * lease on the same property. It is a separate file with no metadata; the original
 * evidence is never altered, and nothing of the earlier tenant travels with it.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $lease_id the receiving tenancy
 * @property int|null $source_lease_id
 * @property int|null $source_evidence_link_id
 * @property int|null $area_room_id
 * @property string $storage_path
 * @property string $mime_type
 * @property string $sha256_hash
 * @property bool $edited true when staff supplied a cropped or blurred copy
 * @property string|null $description staff's neutral description
 * @property int|null $approved_by_user_id
 * @property \Illuminate\Support\Carbon $approved_at
 * @property \Illuminate\Support\Carbon|null $withdrawn_at
 * @property string|null $tenant_response
 * @property string|null $tenant_response_note
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property-read AreaRoom|null $areaRoom
 */
final class SharedConditionPhoto extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const RESPONSES = ['confirmed', 'disputed'];

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'lease_id',
        'source_lease_id',
        'source_evidence_link_id',
        'area_room_id',
        'storage_path',
        'mime_type',
        'sha256_hash',
        'edited',
        'description',
        'approved_by_user_id',
        'approved_at',
        'withdrawn_at',
        'tenant_response',
        'tenant_response_note',
        'responded_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'edited' => 'boolean',
            'approved_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    /**
     * What staff see, including where the photo came from. Never sent to a tenant.
     *
     * @return array<string, mixed>
     */
    public function staffView(): array
    {
        return [
            'id' => $this->id,
            'source_lease_id' => $this->source_lease_id,
            'source_evidence_link_id' => $this->source_evidence_link_id,
            'area_room_id' => $this->area_room_id,
            'edited' => $this->edited,
            'description' => $this->description,
            'approved_by_user_id' => $this->approved_by_user_id,
            'approved_at' => $this->approved_at->toIso8601String(),
            'withdrawn_at' => $this->withdrawn_at?->toIso8601String(),
            'tenant_response' => $this->tenant_response,
            'tenant_response_note' => $this->tenant_response_note,
            'responded_at' => $this->responded_at?->toIso8601String(),
        ];
    }

    /** @return BelongsTo<AreaRoom, $this> */
    public function areaRoom(): BelongsTo
    {
        return $this->belongsTo(AreaRoom::class);
    }
}
