<?php

declare(strict_types=1);

namespace App\Services;

use App\Factories\AreaRoomWriteFactory;
use App\Factories\LeaseWriteFactory;
use App\Models\AreaRoom;
use App\Models\AuditLog;
use App\Models\CaptureIssue;
use App\Models\CaptureRoomRating;
use App\Models\EvidenceLink;
use App\Models\InspectionSection;
use App\Models\Lease;
use App\Repositories\AreaRoomRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Merges and splits a unit's rooms. Photos are re-filed, never altered, and each
 * move is saved through its model so the audit log keeps the history.
 */
final class RoomReorganiseService
{
    public function __construct(
        private readonly AreaRoomRepository $rooms,
        private readonly AreaRoomWriteFactory $factory,
        private readonly LeaseWriteFactory $leases,
    ) {}

    public function isInspected(AreaRoom $room): bool
    {
        return InspectionSection::query()->where('area_room_id', $room->id)->exists();
    }

    public function merge(AreaRoom $source, AreaRoom $target, int $userId): void
    {
        DB::transaction(function () use ($source, $target, $userId): void {
            $photos = EvidenceLink::query()->where('area_room_id', $source->id)->get();
            $photos->each(static fn(EvidenceLink $photo) => $photo->update(['area_room_id' => $target->id]));

            EvidenceLink::query()
                ->where('attachable_type', $source->getMorphClass())
                ->where('attachable_id', $source->id)
                ->get()
                ->each(static fn(EvidenceLink $link) => $link->update(['attachable_id' => $target->id]));

            foreach ($this->leasesListing($source) as $lease) {
                $this->leases->addCoveredArea($lease, $target->id);
            }

            $this->mergeNotes($source, $target);

            AreaRoom::query()
                ->where('adjoins_area_room_id', $source->id)
                ->whereKeyNot($target->id)
                ->update(['adjoins_area_room_id' => $target->id]);

            $captureKey = $source->capture_key;
            $this->factory->delete($source);

            // A photo still queued on the tenant's phone then follows the merged room.
            if ($captureKey !== null && $target->capture_key === null) {
                $this->factory->update($target, ['capture_key' => $captureKey]);
            }

            $this->audit($target, 'room_merged', $userId, [
                'merged_room_id' => $source->id,
                'merged_room_name' => $source->name,
                'photo_ids' => $photos->modelKeys(),
            ]);
        });
    }

    /**
     * Moves the chosen photos to a new room. Null when one of them is not in the source room.
     *
     * @param list<int> $photoIds
     */
    public function split(AreaRoom $source, string $name, array $photoIds, int $userId): ?AreaRoom
    {
        /** @var AreaRoom|null $room */
        $room = DB::transaction(function () use ($source, $name, $photoIds, $userId): ?AreaRoom {
            $photos = EvidenceLink::query()->where('area_room_id', $source->id)->whereKey($photoIds)->get();

            if ($photos->count() !== count($photoIds)) {
                return null;
            }

            $created = $this->factory->create([
                'organisation_id' => $source->getAttribute('organisation_id'),
                'property_id' => $source->property_id,
                'name' => $name,
                'room_type' => $source->room_type,
                'sort_order' => $this->rooms->nextSortOrder((int) $source->property_id),
            ]);

            $photos->each(static fn(EvidenceLink $photo) => $photo->update(['area_room_id' => $created->id]));
            $this->followCloseUps($source, $created);

            foreach ($this->leasesListing($source) as $lease) {
                $this->leases->addCoveredArea($lease, $created->id);
            }

            $this->audit($created, 'room_split', $userId, [
                'split_from_room_id' => $source->id,
                'split_from_room_name' => $source->name,
                'photo_ids' => $photos->modelKeys(),
            ]);

            return $created;
        });

        return $room;
    }

    /** Where both rooms were rated for one capture, the rating of the room kept stands. */
    private function mergeNotes(AreaRoom $source, AreaRoom $target): void
    {
        CaptureIssue::query()
            ->where('area_room_id', $source->id)
            ->get()
            ->each(static fn(CaptureIssue $issue) => $issue->update(['area_room_id' => $target->id]));

        foreach (CaptureRoomRating::query()->where('area_room_id', $source->id)->get() as $rating) {
            $isRated = CaptureRoomRating::query()
                ->where('area_room_id', $target->id)
                ->where('lease_id', $rating->lease_id)
                ->where('phase', $rating->phase)
                ->exists();

            if ($isRated) {
                $rating->delete();
                continue;
            }

            $rating->update(['area_room_id' => $target->id]);
        }
    }

    /** An issue moves with a split only when all its close-ups did. */
    private function followCloseUps(AreaRoom $source, AreaRoom $created): void
    {
        CaptureIssue::query()
            ->where('area_room_id', $source->id)
            ->whereHas('photos', static fn($photos) => $photos->where('area_room_id', $created->id))
            ->whereDoesntHave('photos', static fn($photos) => $photos->where('area_room_id', $source->id))
            ->get()
            ->each(static fn(CaptureIssue $issue) => $issue->update(['area_room_id' => $created->id]));
    }

    /** @return Collection<int, Lease> */
    private function leasesListing(AreaRoom $room): Collection
    {
        return Lease::query()->whereHas('coveredAreas', static fn($areas) => $areas->where(
            'areas_rooms.id',
            $room->id,
        ))->get();
    }

    /** @param array<string, mixed> $values */
    private function audit(AreaRoom $room, string $event, int $userId, array $values): void
    {
        AuditLog::query()->create([
            'organisation_id' => $room->getAttribute('organisation_id'),
            'actor_user_id' => $userId,
            'auditable_type' => $room->getMorphClass(),
            'auditable_id' => $room->id,
            'event' => $event,
            'new_values' => $values,
        ]);
    }
}
