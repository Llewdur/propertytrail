<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaptureIssue;
use App\Models\CaptureLink;
use App\Models\CaptureRoomRating;
use App\Models\Lease;
use App\Models\Property;
use App\Repositories\AreaRoomRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What a tenant signs: the rooms their lease covers, with their ratings, reported
 * problems and the fingerprints of their photos, frozen in a fixed order so the
 * same capture always hashes the same.
 */
final class CaptureSignOffContent
{
    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly AreaRoomRepository $areas,
    ) {}

    /**
     * @return array{lease_id: int, phase: string, property_name: string, tenant_name: string, rooms: list<array{id: int, name: string, condition: ?string, photos: list<string>, issues: list<array{kind: string, note: string, photos: list<string>}>}>}
     */
    public function snapshot(CaptureLink $link): array
    {
        $lease = Lease::query()->findOrFail($link->lease_id);

        /** @var array<int, array<int, list<string>>> $hashes photo hashes by room, then issue (0 for none) */
        $hashes = [];

        foreach ($this->links->photos($link)->get() as $photo) {
            $sha = (string) $photo->evidenceRecord->getAttribute('sha256_hash');
            $hashes[(int) $photo->area_room_id][(int) $photo->capture_issue_id][] = $sha;
        }

        /** @var array<int, string> $conditions the latest rating per room */
        $conditions = [];

        foreach ($this->captured(CaptureRoomRating::query(), $link) as $rating) {
            $conditions[(int) $rating->area_room_id] = $rating->condition;
        }

        /** @var array<int, list<array{kind: string, note: string, photos: list<string>}>> $issues */
        $issues = [];

        foreach ($this->captured(CaptureIssue::query(), $link) as $issue) {
            $room = (int) $issue->area_room_id;
            $issues[$room][] = [
                'kind' => $issue->kind,
                'note' => $issue->note,
                'photos' => $this->sorted($hashes[$room][$issue->id] ?? []),
            ];
        }

        $rooms = [];

        foreach ($this->areas->coveredByLease($lease) as $area) {
            $id = (int) $area->getKey();
            $rooms[] = [
                'id' => $id,
                'name' => (string) $area->getAttribute('name'),
                'condition' => $conditions[$id] ?? null,
                'photos' => $this->sorted($hashes[$id][0] ?? []),
                'issues' => $issues[$id] ?? [],
            ];
        }

        return [
            'lease_id' => $link->lease_id,
            'phase' => $link->phase,
            'property_name' => (string) Property::query()->findOrFail($lease->property_id)->getAttribute('name'),
            'tenant_name' => $lease->tenant_name,
            'rooms' => $rooms,
        ];
    }

    /**
     * The room-by-room summary the tenant reviews: counts and notes, no photo hashes.
     *
     * @param list<array{id: int, name: string, condition: ?string, photos: list<string>, issues: list<array{kind: string, note: string, photos: list<string>}>}> $rooms
     *
     * @return list<array{name: string, condition: ?string, photo_count: int, issues: list<array{kind: string, note: string}>}>
     */
    public function summary(array $rooms): array
    {
        $summary = [];

        foreach ($rooms as $room) {
            $summary[] = [
                'name' => $room['name'],
                'condition' => $room['condition'],
                'photo_count' => count($room['photos']),
                'issues' => array_map(static fn(array $issue): array => [
                    'kind' => $issue['kind'],
                    'note' => $issue['note'],
                ], $room['issues']),
            ];
        }

        return $summary;
    }

    /** @param array<string, mixed> $content */
    public function hash(array $content): string
    {
        return hash('sha256', json_encode(
            $content,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * The tenant's own notes for this lease and phase, oldest first.
     *
     * @template TModel of CaptureRoomRating|CaptureIssue
     *
     * @param Builder<TModel> $query
     *
     * @return Collection<int, TModel>
     */
    private function captured(Builder $query, CaptureLink $link): Collection
    {
        /** @var Collection<int, TModel> $notes */
        $notes = $query
            ->where('lease_id', $link->lease_id)
            ->where('phase', $link->phase)
            ->get()
            ->sortBy([
                ['recorded_at', 'asc'],
                ['id',          'asc'],
            ])
            ->values();

        return $notes;
    }

    /**
     * @param list<string> $hashes
     *
     * @return list<string>
     */
    private function sorted(array $hashes): array
    {
        sort($hashes);

        return $hashes;
    }
}
