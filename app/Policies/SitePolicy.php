<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Tenancy\CurrentOrganisation;

/** A site spans units, so only an organisation admin may change one. */
final class SitePolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdminIn(app(CurrentOrganisation::class)->id() ?? 0);
    }
}
