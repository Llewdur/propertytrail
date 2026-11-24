<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EvidenceLink;
use App\Repositories\EvidenceRepository;
use App\Repositories\PropertyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LeasePhotoController extends Controller
{
    public function __construct(
        private readonly EvidenceRepository $evidence,
        private readonly PropertyRepository $properties,
    ) {}

    public function index(Request $request, int $property): JsonResponse
    {
        if ($this->properties->find($property) === null) {
            return response()->json(['message' => 'Property not found.'], Response::HTTP_NOT_FOUND);
        }

        /** @var array<string, mixed> $filters */
        $filters = $request->validate([
            'lease_id' => ['sometimes', 'nullable', 'integer'],
            'phase' => ['sometimes', 'nullable', 'string', 'in:incoming,outgoing,interim'],
        ]);

        /** @var iterable<int, EvidenceLink> $links */
        $links = $this->evidence->leasePhotos(
            $property,
            is_numeric($filters['lease_id'] ?? null) ? (int) $filters['lease_id'] : null,
            is_string($filters['phase'] ?? null) ? $filters['phase'] : null,
        );

        $groups = [];

        foreach ($links as $link) {
            $roomId = (int) ($link->area_room_id ?? 0);

            if (!array_key_exists($roomId, $groups)) {
                $groups[$roomId] = [
                    'room' => $roomId === 0 ? null : ['id' => $roomId, 'name' => $link->areaRoom?->name],
                    'photos' => [],
                ];
            }

            $groups[$roomId]['photos'][] = [
                'id' => $link->id,
                'label' => $link->label,
                'phase' => $link->phase,
                'taken_at' => $link->taken_at?->toISOString(),
                'record' => [
                    'id' => $link->evidenceRecord->id,
                    'file_name' => $link->evidenceRecord->file_name,
                    'mime_type' => $link->evidenceRecord->mime_type,
                    'file_size_bytes' => $link->evidenceRecord->file_size_bytes,
                    'captured_at' => $link->evidenceRecord->captured_at?->toISOString(),
                ],
            ];
        }

        return response()->json([
            'data' => [
                'property_id' => $property,
                'groups' => array_values($groups),
            ],
        ]);
    }
}
