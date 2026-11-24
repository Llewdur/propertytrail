<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organisation;
use App\Models\User;

final class OrganisationPolicy
{
    public function manageTeam(User $user, Organisation $organisation): bool
    {
        return $user->isAdminIn($organisation);
    }

    public function manageTemplates(User $user, Organisation $organisation): bool
    {
        return $user->isAdminIn($organisation);
    }
}
