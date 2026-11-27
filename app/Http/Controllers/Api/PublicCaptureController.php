<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Adapters\StorageAdapterInterface;
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
    private const TOKEN_HEADER = 'X-Capture-Token';

    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly AreaRoomRepository $areas,
        private readonly StorageAdapterInterface $storage,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $link = $this->link($request);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $lease = Lease::query()->findOrFail($link->lease_id);
        $photos = $this->links->photos($link)->orderBy('id')->get()->groupBy('area_room_id');

        $areas = [];

        foreach ($this->areas->coveredByLease($lease) as $area) {
            $areas[] = [
                'id' => $area->getKey(),
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
        $link = $this->link($request);

        if (!$link instanceof CaptureLink) {
            return $link;
        }

        $areaId = (int) $request->validated('area_room_id');
        $lease = Lease::query()->findOrFail($link->lease_id);
        $file = $request->file('file');

        if (!$file instanceof UploadedFile || !$this->areas->coveredByLease($lease)->contains('id', $areaId)) {
            return response()->json([
                'message' => 'This link does not cover that room or area.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->links->photos($link)->count() >= CaptureLinkService::MAX_PHOTOS) {
            return response()->json([
                'message' => 'The photo limit for this capture has been reached.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->private(['data' => $this->photoPayload($this->links->addPhoto(
            $link,
            $file,
            $areaId,
        ))], Response::HTTP_CREATED);
    }

    public function photo(Request $request, int $photo): Response
    {
        $link = $this->link($request);

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

    /** The live link, or the refusal to send back. */
    private function link(Request $request): CaptureLink|JsonResponse
    {
        $link = $this->links->resolve((string) $request->header(self::TOKEN_HEADER, ''));

        if ($link === null) {
            return response()->json(['message' => 'This link is not valid.'], Response::HTTP_NOT_FOUND);
        }

        if (!$link->isLive()) {
            return response()->json([
                'message' => 'This link has expired or was withdrawn. Ask for a new one; your photos are saved.',
            ], Response::HTTP_GONE);
        }

        return $link;
    }

    /** @return array{id: int, taken_at: ?string} */
    private function photoPayload(EvidenceLink $photo): array
    {
        return ['id' => $photo->id, 'taken_at' => $photo->taken_at?->toIso8601String()];
    }

    /** @param array<string, mixed> $body */
    private function private(array $body, int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store, private']);
    }
}
