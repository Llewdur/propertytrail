<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a tenant reports as broken, damaged or missing. Their statement, not a finding or a liability decision.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $lease_id
 * @property string $phase
 * @property int|null $area_room_id
 * @property int|null $capture_link_id
 * @property string $recorded_by
 * @property \Illuminate\Support\Carbon $recorded_at
 * @property string $issue_key
 * @property string $kind
 * @property string $note
 */
final class CaptureIssue extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const KINDS = ['broken', 'damaged', 'missing'];

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'lease_id',
        'phase',
        'area_room_id',
        'capture_link_id',
        'recorded_by',
        'recorded_at',
        'issue_key',
        'kind',
        'note',
    ];

    /** @return HasMany<EvidenceLink, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(EvidenceLink::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }
}
