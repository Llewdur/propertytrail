<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $property_id
 * @property string $lease_reference
 * @property string $tenant_name
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property string $status
 * @property int|null $previous_lease_id the tenancy this one follows on the same property
 * @property \Illuminate\Support\Carbon|null $moved_out_at
 */
final class Lease extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const STATUS_ENDED = 'ended';

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'property_id',
        'previous_lease_id',
        'lease_reference',
        'tenant_name',
        'start_date',
        'end_date',
        'status',
        'moved_out_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'moved_out_at' => 'datetime',
        ];
    }

    public function hasMovedOut(): bool
    {
        return $this->moved_out_at !== null;
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsToMany<AreaRoom, $this> */
    public function coveredAreas(): BelongsToMany
    {
        return $this->belongsToMany(AreaRoom::class, 'lease_areas')->withTimestamps();
    }

    /** @return MorphMany<EvidenceLink, $this> */
    public function photos(): MorphMany
    {
        return $this->morphMany(EvidenceLink::class, 'attachable');
    }
}
