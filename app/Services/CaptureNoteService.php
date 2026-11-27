<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaptureIssue;
use App\Models\CaptureLink;
use App\Models\CaptureRoomRating;
use App\Models\Lease;
use App\Support\CaptureTime;
use Illuminate\Support\Facades\DB;

/** Saves what a tenant says about a room: its condition rating and anything broken, damaged or missing. */
final class CaptureNoteService
{
    public const MAX_ISSUES = 300;

    public function __construct(
        private readonly CaptureRoomService $rooms,
    ) {}

    /**
     * A retried or repeated note updates the one already held. A room with no
     * photo yet is created, so a tenant's statement is never lost with it.
     * Null means saved; otherwise the reason it was refused.
     *
     * @param array<string, mixed> $data validated note fields
     */
    public function record(CaptureLink $link, Lease $lease, array $data): ?string
    {
        /** @var string|null $refusal */
        $refusal = DB::transaction(function () use ($link, $lease, $data): ?string {
            Lease::query()->lockForUpdate()->find($link->lease_id);
            $areaId = $this->rooms->roomId($lease, $data);

            if ($areaId === null) {
                return 'This link does not cover that room or area.';
            }

            $statement = [
                'organisation_id' => $lease->organisation_id,
                'capture_link_id' => $link->id,
                'recorded_by' => $lease->tenant_name,
                'recorded_at' => CaptureTime::from($data['taken_at'] ?? null),
            ];
            $capture = ['lease_id' => $link->lease_id, 'phase' => $link->phase];
            $key = (string) ($data['issue_key'] ?? '');

            if ($key === '') {
                CaptureRoomRating::query()->updateOrCreate([...$capture, 'area_room_id' => $areaId], [
                    ...$statement,
                    'condition' => (string) $data['condition'],
                ]);

                return null;
            }

            $issues = CaptureIssue::query()->where($capture);

            if (!(clone $issues)->where('issue_key', $key)->exists() && $issues->count() >= self::MAX_ISSUES) {
                return 'The limit of reported problems for this capture has been reached.';
            }

            CaptureIssue::query()->updateOrCreate([...$capture, 'issue_key' => $key], [
                ...$statement,
                'area_room_id' => $areaId,
                'kind' => (string) $data['issue_kind'],
                'note' => (string) $data['issue_note'],
            ]);

            return null;
        });

        return $refusal;
    }
}
