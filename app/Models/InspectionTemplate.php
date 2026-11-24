<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $room_type
 * @property string $name
 * @property bool $is_active
 * @property-read Collection<int, InspectionTemplateItem> $items
 */
final class InspectionTemplate extends Model
{
    use Auditable;
    use BelongsToOrganisation;

    /** @use HasFactory<Factory<Model>> */
    use HasFactory;

    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'room_type',
        'name',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<InspectionTemplateItem, $this> */
    public function items(): HasMany
    {
        $relation = $this->hasMany(InspectionTemplateItem::class);
        $relation->getQuery()->orderBy('sort_order')->orderBy('id');

        return $relation;
    }
}
