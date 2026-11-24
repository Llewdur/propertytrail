<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\AreaRoom;

final class AreaRoomWriteFactory
{
    /** @param array<string, mixed> $data */
    public function create(array $data): AreaRoom
    {
        return AreaRoom::query()->create($data);
    }

    /** @param array<string, mixed> $data */
    public function update(AreaRoom $room, array $data): AreaRoom
    {
        $room->fill($data)->save();

        return $room;
    }

    public function delete(AreaRoom $room): void
    {
        $room->delete();
    }
}
