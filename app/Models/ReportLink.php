<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $organisation_id
 * @property int $inspection_report_id
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 */
final class ReportLink extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = ['organisation_id', 'inspection_report_id', 'token_hash', 'expires_at', 'revoked_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InspectionReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(InspectionReport::class, 'inspection_report_id');
    }

    public static function hash(#[\SensitiveParameter] string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
