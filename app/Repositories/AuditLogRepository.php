<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AuditLog;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class AuditLogRepository
{
    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    /** @return Collection<int, AuditLog> */
    public function recent(int $limit = 100): Collection
    {
        return $this->inOrganisation()->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Everything that happened on one tenancy: the tenant's own actions and
     * staff changes to, and views of, the tenant's material.
     *
     * @return Collection<int, AuditLog>
     */
    public function forLease(int $leaseId, int $limit): Collection
    {
        return $this->inOrganisation()->where('lease_id', $leaseId)->orderByDesc('id')->limit($limit)->get();
    }

    /**
     * Only what the tenant on this tenancy did: entries with no staff actor.
     *
     * @return Collection<int, AuditLog>
     */
    public function tenantActions(int $leaseId, int $limit): Collection
    {
        return $this
            ->inOrganisation()
            ->where('lease_id', $leaseId)
            ->whereNull('actor_user_id')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** @return Collection<int, AuditLog> */
    public function forActor(int $userId, int $limit): Collection
    {
        return $this->inOrganisation()->where('actor_user_id', $userId)->orderByDesc('id')->limit($limit)->get();
    }

    /** @return Builder<AuditLog> */
    private function inOrganisation(): Builder
    {
        return AuditLog::query()->where('organisation_id', $this->currentOrganisation->id() ?? -1);
    }
}
