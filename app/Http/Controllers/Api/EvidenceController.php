<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadEvidenceRequest;
use App\Models\AreaRoom;
use App\Models\InspectionItem;
use App\Models\Lease;
use App\Models\Property;
use App\Repositories\EvidenceRepository;
use App\Services\AttachableResolver;
use App\Services\EvidenceIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class EvidenceController extends Controller
{
    private const ATTACHABLE_TYPES = [
        'property' => Property::class,
        'room' => AreaRoom::class,
        'lease' => Lease::class,
        'inspection_item' => InspectionItem::class,
    ];

    public function __construct(
        private readonly EvidenceIngestionService $ingestion,
        private readonly EvidenceRepository $evidence,
        private readonly AttachableResolver $attachables,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->evidence->all()]);
    }

    public function show(int $evidence): JsonResponse
    {
        $record = $this->evidence->find($evidence);

        if ($record === null) {
            return response()->json(['message' => 'Evidence record not found.'], Response::HTTP_NOT_FOUND);
        }

        return response()->json(['data' => $record]);
    }

    public function upload(UploadEvidenceRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $file = $request->file('file');

        if (!$file instanceof UploadedFile) {
            return response()->json([
                'message' => 'The file field must be a valid upload.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $type = self::ATTACHABLE_TYPES[$data['attachable_type']];

        if (!$this->attachables->exists($type, (int) $data['attachable_id'])) {
            return response()->json(['message' => 'Attachable record not found.'], Response::HTTP_NOT_FOUND);
        }

        $refusal = $this->attachables->refusal($type, (int) $data['attachable_id'], $data);

        if ($refusal !== null) {
            return response()->json(['message' => $refusal], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array<string, mixed> $context */
        $context = [
            'phase' => $data['phase'] ?? null,
            'area_room_id' => $data['area_room_id'] ?? null,
            'taken_at' => $data['taken_at'] ?? null,
            'replaces_link_id' => $data['replaces_link_id'] ?? null,
        ];

        $record = $this->ingestion->ingest(
            $file,
            $type,
            (int) $data['attachable_id'],
            $data['label'] ?? null,
            $context,
        );

        return response()->json(['data' => $record->load('links')], Response::HTTP_CREATED);
    }
}
