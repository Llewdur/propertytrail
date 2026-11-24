<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Organisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds the organisation relation and ownership helpers to a tenant-scoped
 * model. Pair with OrganisationScoped, which adds the query filtering.
 *
 * @mixin Model
 */
trait BelongsToOrganisation
{
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function belongsToOrganisation(int $organisationId): bool
    {
        return (int) $this->organisation_id === $organisationId;
    }
}
