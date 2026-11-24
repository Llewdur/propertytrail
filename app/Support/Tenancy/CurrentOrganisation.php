<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Holds the organisation id that every tenant-scoped query is filtered to for
 * the current request. Bound as a singleton in AppServiceProvider and set by
 * the tenancy middleware or by an explicitly authenticated caller — never by
 * an untrusted request parameter. No value means no organisation resolved:
 * scoped queries then return nothing, which is the safe default.
 */
final class CurrentOrganisation
{
    private ?int $organisationId = null;

    public function set(int $organisationId): void
    {
        $this->organisationId = $organisationId;
    }

    public function id(): ?int
    {
        return $this->organisationId;
    }

    public function clear(): void
    {
        $this->organisationId = null;
    }
}
