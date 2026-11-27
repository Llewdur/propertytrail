<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MergeRoomRequest;
use App\Http\Requests\SplitRoomRequest;
use App\Models\AreaRoom;
use App\Repositories\AreaRoomRepository;
use App\Services\RoomReorganiseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Staff tidying of rooms after a capture. Renaming is the ordinary room update. */
final class RoomReorganiseController extends Controller
{
    public function __construct(
        private readonly AreaRoomRepository $rooms,
        private readonly RoomReorganiseService $reorganise,
    ) {}

    public function merge(MergeRoomRequest $request, int $room): JsonResponse
    {
        $source = $this->rooms->find($room);
        $target = $this->rooms->find($request->integer('into_room_id'));

        if ($source === null) {
            return $this->notFound();
        }

        if ($target === null || $target->id === $source->id || $target->property_id !== $source->property_id) {
            return $this->refuse('Choose another room of the same property.');
        }

        if ($this->reorganise->isInspected($source)) {
            return $this->refuse('An inspection records this room, so it cannot be merged away. Merge the other way.');
        }

        $this->reorganise->merge($source, $target, (int) Auth::id());

        return response()->json(['data' => $target]);
    }

    public function split(SplitRoomRequest $request, int $room): JsonResponse
    {
        $source = $this->rooms->find($room);

        if ($source === null) {
            return $this->notFound();
        }

        /** @var array{name: string, photo_ids: list<int|string>} $data */
        $data = $request->validated();
        $created = $this->reorganise->split(
            $source,
            $data['name'],
            array_map(intval(...), $data['photo_ids']),
            (int) Auth::id(),
        );

        return (
            $created instanceof AreaRoom
                ? response()->json(['data' => $created], Response::HTTP_CREATED)
                : $this->refuse('A chosen photo is not in this room.')
        );
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Room not found.'], Response::HTTP_NOT_FOUND);
    }

    private function refuse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
