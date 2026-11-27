<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical address that may hold several lettable units. Its areas are the
 * shared and outdoor ones that belong to no single unit.
 *
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property string $site_type
 */
final class Site extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var list<string> */
    public const TYPES = ['standalone_house', 'sectional_title', 'multi_unit'];

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'name',
        'site_type',
    ];

    /** @return HasMany<AreaRoom, $this> */
    public function areas(): HasMany
    {
        $areas = $this->hasMany(AreaRoom::class);
        $areas->orderBy('sort_order')->orderBy('id');

        return $areas;
    }

    /** @return HasMany<Property, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Property::class);
    }
}
