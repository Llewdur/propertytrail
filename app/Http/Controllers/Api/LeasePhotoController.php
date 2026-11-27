<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AreaRoom;
use App\Models\EvidenceLink;
use App\Repositories\CaptureNoteRepository;
use App\Repositories\EvidenceRepository;
use App\Repositories\PropertyRepository;
use App\Support\ConditionVocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LeasePhotoController extends Controller
{
    public function __construct(
        private readonly EvidenceRepository $evidence,
        private readonly PropertyRepository $properties,
        private readonly CaptureNoteRepository $notes,
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

        $leaseId = is_numeric($filters['lease_id'] ?? null) ? (int) $filters['lease_id'] : null;
        $phase = is_string($filters['phase'] ?? null) ? $filters['phase'] : null;

        /** @var iterable<int, EvidenceLink> $links */
        $links = $this->evidence->leasePhotos($property, $leaseId, $phase);

        $groups = [];

        foreach ($links as $link) {
            $roomId = (int) ($link->area_room_id ?? 0);

            if (!array_key_exists($roomId, $groups)) {
                $groups[$roomId] = [
                    'room' => $roomId === 0 ? null : ['id' => $roomId, 'name' => $link->areaRoom?->name],
                    'photos' => [],
                    'ratings' => [],
                    'issues' => [],
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

        $groups = $this->withTenantNotes($groups, $property, $leaseId, $phase);

        return response()->json([
            'data' => [
                'property_id' => $property,
                'groups' => array_values($groups),
            ],
        ]);
    }

    /**
     * Adds what the tenant said about each room, including rooms with no photo.
     *
     * @param array<int, array<string, mixed>> $groups keyed by room id
     * @return array<int, array<string, mixed>>
     */
    private function withTenantNotes(array $groups, int $property, ?int $leaseId, ?string $phase): array
    {
        $ratings = $this->notes->ratings($property, $leaseId, $phase);
        $issues = $this->notes->issues($property, $leaseId, $phase);
        $names = AreaRoom::query()
            ->whereKey(
                $ratings->pluck('area_room_id')->merge($issues->pluck('area_room_id'))->filter()->unique()->all(),
            )
            ->pluck('name', 'id');
        $empty = static fn(int $roomId): array => [
            'room' => $roomId === 0 ? null : ['id' => $roomId, 'name' => $names->get($roomId)],
            'photos' => [],
            'ratings' => [],
            'issues' => [],
        ];

        foreach ($ratings as $rating) {
            $roomId = (int) ($rating->area_room_id ?? 0);
            $groups[$roomId] ??= $empty($roomId);
            $groups[$roomId]['ratings'][] = [
                'id' => $rating->id,
                'condition' => ConditionVocabulary::label($rating->condition),
                'phase' => $rating->phase,
                'recorded_by' => $rating->recorded_by,
                'recorded_at' => $rating->recorded_at->toISOString(),
            ];
        }

        foreach ($issues as $issue) {
            $roomId = (int) ($issue->area_room_id ?? 0);
            $groups[$roomId] ??= $empty($roomId);
            $groups[$roomId]['issues'][] = [
                'id' => $issue->id,
                'kind' => $issue->kind,
                'note' => $issue->note,
                'phase' => $issue->phase,
                'recorded_by' => $issue->recorded_by,
                'recorded_at' => $issue->recorded_at->toISOString(),
                'photo_ids' => $issue->photos->modelKeys(),
            ];
        }

        return $groups;
    }
}
