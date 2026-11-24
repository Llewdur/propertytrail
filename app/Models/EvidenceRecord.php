<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $file_name
 * @property string $mime_type
 * @property int $file_size_bytes
 * @property \Illuminate\Support\Carbon|null $captured_at
 */
final class EvidenceRecord extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use HasFactory;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'sha256_hash',
        'file_name',
        'mime_type',
        'file_size_bytes',
        'storage_driver',
        'storage_path',
        'captured_at',
        'gps_latitude',
        'gps_longitude',
        'camera_make',
        'camera_model',
        'uploaded_by_user_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'captured_at' => 'datetime',
        'gps_latitude' => 'decimal:7',
        'gps_longitude' => 'decimal:7',
    ];

    /** @return HasMany<EvidenceLink, $this> */
    public function links(): HasMany
    {
        return $this->hasMany(EvidenceLink::class);
    }
}
