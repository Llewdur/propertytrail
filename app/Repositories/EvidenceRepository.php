<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\Lease;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

final class EvidenceRepository
{
    public function findByHash(string $hash): ?EvidenceRecord
    {
        return EvidenceRecord::query()->where('sha256_hash', $hash)->first();
    }

    public function find(int $id): ?EvidenceRecord
    {
        return EvidenceRecord::query()->with('links')->find($id);
    }

    /** @return Collection<int, EvidenceRecord> */
    public function all(): Collection
    {
        return EvidenceRecord::query()->with('links')->orderByDesc('id')->get();
    }

    public function leasePhotos(int $propertyId, ?int $leaseId, ?string $phase): SupportCollection
    {
        $query = EvidenceLink::query()
            ->with(['evidenceRecord', 'areaRoom'])
            ->join('leases', 'leases.id', '=', 'evidence_links.attachable_id')
            ->leftJoin('areas_rooms', 'areas_rooms.id', '=', 'evidence_links.area_room_id')
            ->where('evidence_links.attachable_type', new Lease()->getMorphClass())
            ->where('leases.property_id', $propertyId);

        if ($leaseId !== null) {
            $query->where('leases.id', $leaseId);
        }

        if ($phase !== null) {
            $query->where('evidence_links.phase', $phase);
        }

        return $query
            ->select('evidence_links.*')
            ->orderBy('areas_rooms.sort_order')
            ->orderBy('evidence_links.taken_at')
            ->orderBy('evidence_links.id')
            ->get();
    }
}
