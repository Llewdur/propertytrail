<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AuditLog;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Database\Eloquent\Collection;

final class AuditLogRepository
{
    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    /** @return Collection<int, AuditLog> */
    public function recent(int $limit = 100): Collection
    {
        return AuditLog::query()
            ->where('organisation_id', $this->currentOrganisation->id() ?? -1)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
