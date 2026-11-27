<?php

declare(strict_types=1);

namespace App\Services;

use App\Factories\AreaRoomWriteFactory;
use App\Factories\LeaseWriteFactory;
use App\Models\AreaRoom;
use App\Models\Lease;
use App\Repositories\AreaRoomRepository;

/** The room a tenant's photo belongs to, creating a room they named with its first photo. */
final class CaptureRoomService
{
    public const MAX_NEW_ROOMS = 100;

    public function __construct(
        private readonly AreaRoomRepository $areas,
        private readonly AreaRoomWriteFactory $rooms,
        private readonly LeaseWriteFactory $leases,
        private readonly CaptureNeighbourService $neighbours,
    ) {}

    /**
     * Null means the lease does not cover the room, or no more rooms may be added.
     * Call inside a transaction that holds the lease row.
     *
     * @param array<string, mixed> $data validated capture fields
     */
    public function roomId(Lease $lease, array $data): ?int
    {
        $covered = $this->areas->coveredByLease($lease);
        $adjoins = $this->neighbours->find($covered, $data);

        if (($data['area_room_id'] ?? null) !== null) {
            $room = $covered->firstWhere('id', (int) $data['area_room_id']);

            if ($room !== null) {
                $this->neighbours->note($room, $adjoins);
            }

            return $room?->id;
        }

        $key = (string) ($data['room_key'] ?? '');
        $existing = $this->areas->findByCaptureKey($lease->property_id, $key);

        if ($existing !== null) {
            return $covered->contains('id', $existing->id) ? $existing->id : null;
        }

        if ($this->areas->countCaptured($lease->property_id) >= self::MAX_NEW_ROOMS) {
            return null;
        }

        return $this->create($lease, $key, $data, $adjoins)->id;
    }

    /** @param array<string, mixed> $data */
    private function create(Lease $lease, string $key, array $data, ?AreaRoom $adjoins): AreaRoom
    {
        $hasListedAreas = $this->areas->listedForLease($lease)->isNotEmpty();
        $outdoor = filter_var($data['room_outdoor'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $room = $this->rooms->create([
            'organisation_id' => $lease->organisation_id,
            'property_id' => $lease->property_id,
            'name' => (string) ($data['room_name'] ?? ''),
            'room_type' => $outdoor ? 'outdoor' : 'other',
            'sort_order' => $this->areas->nextSortOrder($lease->property_id),
            'capture_key' => $key,
            'adjoins_area_room_id' => $adjoins?->id,
        ]);

        // With nothing listed the lease already covers every room of the unit; listing one would narrow it.
        if ($hasListedAreas) {
            $this->leases->addCoveredArea($lease, $room->id);
        }

        return $room;
    }
}
