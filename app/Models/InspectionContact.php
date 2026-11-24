<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganisation;
use App\Models\Concerns\OrganisationScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * Where one signing party (landlord or tenant) can be reached for an inspection.
 *
 * @property int $id
 * @property int $organisation_id
 * @property int $inspection_id
 * @property string $party
 * @property string|null $email
 * @property string|null $phone
 */
final class InspectionContact extends Model
{
    use BelongsToOrganisation;
    use OrganisationScoped;

    /** @var array<int, string> */
    protected $fillable = ['organisation_id', 'inspection_id', 'party', 'email', 'phone'];
}
