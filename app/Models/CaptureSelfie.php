<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * The front-camera photo a tenant takes before capturing. It shows who appeared to be
 * holding the phone; it does not verify identity, and it is never evidence or AI input.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $lease_id
 * @property string $phase
 * @property int|null $capture_link_id
 * @property string $storage_path
 * @property string $mime_type
 * @property int $file_size_bytes
 * @property string $sha256_hash
 * @property \Illuminate\Support\Carbon $taken_at
 */
final class CaptureSelfie extends Model
{
    use Auditable;
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** A retake adds one; the cap stops a link being used as free storage. */
    public const MAX_PER_PHASE = 5;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'lease_id',
        'phase',
        'capture_link_id',
        'storage_path',
        'mime_type',
        'file_size_bytes',
        'sha256_hash',
        'taken_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
        ];
    }
}
