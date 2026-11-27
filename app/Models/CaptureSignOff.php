<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * A tenant's signed approval of one version of their capture: the declarations they
 * agreed to, the frozen content and its hash. It shows what was agreed, by whoever
 * held the link; it does not by itself prove identity. Not Auditable on purpose (the
 * trail would copy the signature image); CaptureSignOffService writes one entry.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $lease_id
 * @property string $phase
 * @property int $version
 * @property int|null $capture_link_id
 * @property string $signer_name
 * @property string $signature_image
 * @property string $declaration_version
 * @property list<array{key: string, text: string}> $declarations
 * @property string $content_hash
 * @property array<string, mixed> $content
 * @property string|null $comment
 * @property int|null $capture_selfie_id
 * @property bool $phone_unlocked
 * @property string|null $signer_ip
 * @property string|null $user_agent
 * @property \Illuminate\Support\Carbon $signed_at
 */
final class CaptureSignOff extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = [
        'organisation_id',
        'lease_id',
        'phase',
        'version',
        'capture_link_id',
        'signer_name',
        'signature_image',
        'declaration_version',
        'declarations',
        'content_hash',
        'content',
        'comment',
        'capture_selfie_id',
        'phone_unlocked',
        'signer_ip',
        'user_agent',
        'signed_at',
    ];

    /** @var array<array-key, string> */
    protected $hidden = ['signature_image'];

    /** Short enough to read out over the phone, long enough to tell versions apart. */
    public function reference(): string
    {
        return sprintf(
            'SO-%d-%s-v%d-%s',
            $this->lease_id,
            $this->phase,
            $this->version,
            substr($this->content_hash, 0, 8),
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'declarations' => 'array',
            'content' => 'array',
            'phone_unlocked' => 'boolean',
            'signed_at' => 'datetime',
        ];
    }
}
