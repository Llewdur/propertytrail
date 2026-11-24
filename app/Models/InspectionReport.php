<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The locked artifact: the stored PDF bytes and their SHA-256. Never updated
 * after creation; a changed inspection would be a new version, not a mutation.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $inspection_id
 * @property int $version
 * @property string $reference
 * @property string $storage_path
 * @property string $sha256
 * @property int $size_bytes
 * @property \Illuminate\Support\Carbon $locked_at
 */
final class InspectionReport extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'inspection_id',
        'version',
        'reference',
        'storage_path',
        'sha256',
        'size_bytes',
        'locked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Inspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'reference' => $this->reference,
            'version' => $this->version,
            'sha256' => $this->sha256,
            'size_bytes' => $this->size_bytes,
            'locked_at' => $this->locked_at->toIso8601String(),
        ];
    }
}
