<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Property extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use HasFactory;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'name',
        'reference_code',
        'property_type',
        'address_line_1',
        'address_line_2',
        'city',
        'province',
        'postal_code',
        'gps_latitude',
        'gps_longitude',
        'status',
        'notes',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'gps_latitude' => 'decimal:7',
        'gps_longitude' => 'decimal:7',
    ];

    /** @return HasMany<AreaRoom, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(AreaRoom::class);
    }

    /** @return BelongsTo<Organisation, $this> */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }
}
