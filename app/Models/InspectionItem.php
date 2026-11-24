<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $inspection_section_id
 * @property string $label
 * @property string|null $condition
 * @property string|null $note
 * @property string $source
 * @property int $sort_order
 */
final class InspectionItem extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const SOURCE_DEFAULT = 'default';
    public const SOURCE_MANUAL = 'manual';

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'inspection_section_id',
        'label',
        'condition',
        'note',
        'source',
        'sort_order',
    ];
}
