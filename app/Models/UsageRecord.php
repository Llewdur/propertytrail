<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $kind
 * @property string $subject_type
 * @property int $subject_id
 */
final class UsageRecord extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const KIND_INSPECTION = 'inspection';
    public const KIND_PROPERTY = 'property';
    public const KIND_SIGNING_LINK = 'signing_link';
    public const KIND_UNWATERMARKED_EXPORT = 'unwatermarked_export';

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'kind',
        'subject_type',
        'subject_id',
        'period_start',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period_start' => 'date'];
    }
}
