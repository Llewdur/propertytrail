<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Database\Factories\InspectionTemplateItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $inspection_template_id
 * @property string $label
 * @property bool $requires_note
 * @property int $sort_order
 */
final class InspectionTemplateItem extends Model
{
    use Auditable;
    use BelongsToOrganisation;

    /** @use HasFactory<InspectionTemplateItemFactory> */
    use HasFactory;

    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'inspection_template_id',
        'label',
        'requires_note',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['requires_note' => 'boolean'];
    }

    /** @return BelongsTo<InspectionTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(InspectionTemplate::class);
    }
}
