<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Adapters\StorageAdapterInterface;
use App\Http\Controllers\Api\Concerns\AnswersCaptureRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\CapturePhotoRequest;
use App\Models\CaptureLink;
use App\Models\EvidenceLink;
use App\Models\Lease;
use App\Models\Property;
use App\Repositories\AreaRoomRepository;
use App\Services\CaptureLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account-less tenant capture surface. The token travels in a header, never
 * in the path or query, and reaches only what one lease and phase cover.
 */
final class PublicCaptureController extends Controller
{
    use AnswersCaptureRequests;

    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly AreaRoomRepository $areas,
        private readonly StorageAdapterInterface $storage,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $link = $this->link($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $lease = Lease::query()->findOrFail($link->lease_id);
        $photos = $this->links->photos($link)->orderBy('id')->get()->groupBy('area_room_id');

        $areas = [];

        foreach ($this->areas->coveredByLease($lease) as $area) {
            $areas[] = [
                'id' => $area->getKey(),
                // Lets the phone recognise a room it created itself once the server knows it.
                ...($area->capture_key === null ? [] : ['room_key' => $area->capture_key]),
                'name' => $area->name,
                'photos' => $photos->get($area->getKey())?->map($this->photoPayload(...))->values()->all() ?? [],
            ];
        }

        return $this->private([
            'data' => [
                // Lets the phone match queued photos to the same capture after a new link is issued.
                'capture_key' => $link->lease_id . ':' . $link->phase,
                'property_name' => Property::query()->findOrFail($lease->property_id)->name,
                'tenant_name' => $lease->tenant_name,
                'phase' => $link->phase,
                'expires_at' => $link->expires_at->toIso8601String(),
                'areas' => $areas,
            ],
        ]);
    }

    public function store(CapturePhotoRequest $request): JsonResponse
    {
        $link = $this->link($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $lease = Lease::query()->findOrFail($link->lease_id);
        $file = $request->file('file');

        if (!$file instanceof UploadedFile) {
            return $this->refuse('Choose a photo to upload.');
        }

        if ($this->links->photos($link)->count() >= CaptureLinkService::MAX_PHOTOS) {
            return $this->refuse('The photo limit for this capture has been reached.');
        }

        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $photo = $this->links->capture($link, $lease, $file, $data);

        if ($photo === null) {
            return $this->refuse('This link does not cover that room or area.');
        }

        return $this->private(['data' => [
            ...$this->photoPayload($photo),
            'area_room_id' => $photo->area_room_id,
        ]], Response::HTTP_CREATED);
    }

    public function photo(Request $request, int $photo): Response
    {
        $link = $this->link($request, $this->links);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $held = $this->links->photos($link)->find($photo);
        $contents = $held === null ? null : $this->storage->get($held->evidenceRecord->storage_path);

        if ($held === null || $contents === null) {
            return response()->json(['message' => 'Photo not found.'], Response::HTTP_NOT_FOUND);
        }

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => $held->evidenceRecord->mime_type,
            'Content-Disposition' => 'attachment',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{id: int, taken_at: ?string} */
    private function photoPayload(EvidenceLink $photo): array
    {
        return ['id' => $photo->id, 'taken_at' => $photo->taken_at?->toIso8601String()];
    }
}
