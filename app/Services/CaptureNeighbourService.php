<?php

declare(strict_types=1);

namespace App\Services;

use App\Factories\AreaRoomWriteFactory;
use App\Models\AreaRoom;
use Illuminate\Database\Eloquent\Collection;

/** Which rooms adjoin, from the tenant saying they walked straight from one into the next. */
final class CaptureNeighbourService
{
    public function __construct(
        private readonly AreaRoomWriteFactory $rooms,
    ) {}

    /**
     * @param Collection<int, AreaRoom> $covered
     * @param array<string, mixed> $data validated capture fields
     */
    public function find(Collection $covered, array $data): ?AreaRoom
    {
        $id = (int) ($data['adjoins_area_room_id'] ?? 0);

        if ($id > 0) {
            return $covered->firstWhere('id', $id);
        }

        $key = (string) ($data['adjoins_room_key'] ?? '');

        return $key === '' ? null : $covered->firstWhere('capture_key', $key);
    }

    /** Records the neighbour of a room that existed before the capture. */
    public function note(AreaRoom $room, ?AreaRoom $neighbour): void
    {
        // A site area is shared between units, so one tenant's walk does not describe it.
        if ($room->property_id === null || $room->adjoins_area_room_id !== null) {
            return;
        }

        if ($neighbour !== null && $neighbour->id !== $room->id) {
            $this->rooms->update($room, ['adjoins_area_room_id' => $neighbour->id]);
        }
    }
}
