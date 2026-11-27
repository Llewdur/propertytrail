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
 * @property int $organisation_id
 * @property int $property_id
 * @property string $lease_reference
 * @property string $tenant_name
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon|null $end_date
 * @property string $status
 */
final class Lease extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'property_id',
        'lease_reference',
        'tenant_name',
        'start_date',
        'end_date',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
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
