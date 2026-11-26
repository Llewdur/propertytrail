<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AreaRoom;
use App\Models\EvidenceLink;
use App\Models\EvidenceRecord;
use App\Models\Inspection;
use App\Models\InspectionItem;
use App\Models\InspectionSection;
use App\Models\Lease;
use App\Models\Property;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * @param list<int>|null $propertyIds limits the result to evidence of these properties; null means no limit
     * @return Collection<int, EvidenceRecord>
     */
    public function all(?array $propertyIds = null): Collection
    {
        return EvidenceRecord::query()
            ->with('links')
            ->when($propertyIds
            !== null, fn(Builder $query) => $query->whereHas('links', fn(Builder $links) => $this->whereLinkedToProperties(
                $links,
                $propertyIds ?? [],
            )))
            ->orderByDesc('id')
            ->get();
    }

    /** @param list<int> $propertyIds */
    private function whereLinkedToProperties(Builder $links, array $propertyIds): void
    {
        $inspections = Inspection::query()->whereIn('property_id', $propertyIds)->select('id');
        $sections = InspectionSection::query()->whereIn('inspection_id', $inspections)->select('id');
        $attachables = [
            new Property()->getMorphClass() => Property::query()->whereKey($propertyIds)->select('id'),
            new AreaRoom()->getMorphClass() => AreaRoom::query()->whereIn('property_id', $propertyIds)->select('id'),
            new Lease()->getMorphClass() => Lease::query()->whereIn('property_id', $propertyIds)->select('id'),
            new InspectionItem()->getMorphClass() => InspectionItem::query()
                ->whereIn('inspection_section_id', $sections)
                ->select('id'),
        ];

        $links->where(static function (Builder $query) use ($attachables): void {
            foreach ($attachables as $type => $ids) {
                $query->orWhere(
                    static fn(Builder $match) => $match->where('attachable_type', $type)->whereIn(
                        'attachable_id',
                        $ids,
                    ),
                );
            }
        });
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
