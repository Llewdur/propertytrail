<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Not Auditable on purpose: the audit trail would copy the signature image
 * into every entry. SigningService writes one explicit "signed" entry instead.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $inspection_id
 * @property string $party
 * @property string $signer_name
 * @property string $signature_image
 * @property string $signed_via
 * @property int|null $signed_by_user_id
 * @property string|null $signer_ip
 * @property \Illuminate\Support\Carbon $signed_at
 */
final class InspectionSignature extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    public const PARTY_LANDLORD = 'landlord';
    public const PARTY_TENANT = 'tenant';

    /** @var list<string> */
    public const PARTIES = [self::PARTY_LANDLORD, self::PARTY_TENANT];

    public const VIA_APP = 'app';
    public const VIA_LINK = 'link';

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'inspection_id',
        'party',
        'signer_name',
        'signature_image',
        'signed_via',
        'signed_by_user_id',
        'signer_ip',
        'signed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Inspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /** @return array<string, mixed> */
    public function payload(bool $withImage = false): array
    {
        return (
            [
                'party' => $this->party,
                'signer_name' => $this->signer_name,
                'signed_at' => $this->signed_at->toIso8601String(),
                'signed_via' => $this->signed_via,
            ] + ($withImage ? ['signature_image' => $this->signature_image] : [])
        );
    }
}
