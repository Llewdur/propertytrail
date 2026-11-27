<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CaptureIssue;
use App\Models\CaptureLink;
use App\Models\CaptureRoomRating;
use App\Models\Lease;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class CaptureNoteRepository
{
    /** The tenant's issue a close-up belongs to. The phone sends the issue before its photos. */
    public function findIssue(CaptureLink $link, string $key): ?CaptureIssue
    {
        if ($key === '') {
            return null;
        }

        return CaptureIssue::query()
            ->where('lease_id', $link->lease_id)
            ->where('phase', $link->phase)
            ->where('issue_key', $key)
            ->first();
    }

    /** @return Collection<int, CaptureRoomRating> */
    public function ratings(int $propertyId, ?int $leaseId, ?string $phase): Collection
    {
        /** @var Collection<int, CaptureRoomRating> */
        return $this->filtered(CaptureRoomRating::query(), $propertyId, $leaseId, $phase)->get();
    }

    /** @return Collection<int, CaptureIssue> */
    public function issues(int $propertyId, ?int $leaseId, ?string $phase): Collection
    {
        /** @var Collection<int, CaptureIssue> */
        return $this->filtered(CaptureIssue::query()->with('photos'), $propertyId, $leaseId, $phase)->get();
    }

    private function filtered(Builder $q, int $propertyId, ?int $leaseId, ?string $phase): Builder
    {
        $q->whereIn('lease_id', Lease::query()->where('property_id', $propertyId)->select('id'));

        if ($leaseId !== null) {
            $q->where('lease_id', $leaseId);
        }

        if ($phase !== null) {
            $q->where('phase', $phase);
        }

        $q->orderBy('recorded_at')->orderBy('id');

        return $q;
    }
}
