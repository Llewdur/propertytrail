<?php

declare(strict_types=1);

namespace App\Services;

use App\Factories\LeaseWriteFactory;
use App\Models\AuditLog;
use App\Models\CapturePasskey;
use App\Models\CaptureReminder;
use App\Models\Lease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ends one tenancy and starts the next on the same property. Rooms and layout stay
 * with the property; captures, issues and history stay with the tenancy they came from.
 */
final class TenantTurnoverService
{
    public function __construct(
        private readonly CaptureLinkService $links,
        private readonly LeaseWriteFactory $leases,
    ) {}

    /**
     * Ends the tenant's link to the property: their capture links, phone unlock and
     * reminders stop. The tenancy's record is kept, under the retention rules.
     */
    public function moveOut(Lease $lease, Carbon $endDate, ?int $userId): Lease
    {
        DB::transaction(function () use ($lease, $endDate, $userId): void {
            Lease::query()->lockForUpdate()->find($lease->id);
            $this->links->revoke($lease, $userId);

            $passkeys = (int) CapturePasskey::query()->where('lease_id', $lease->id)->delete();
            $reminders = CaptureReminder::query()
                ->where('lease_id', $lease->id)
                ->whereNull('stopped_at')
                ->update(['stopped_at' => now(), 'stop_reason' => 'moved_out']);

            $lease->forceFill([
                'status' => Lease::STATUS_ENDED,
                'end_date' => $endDate->toDateString(),
                'moved_out_at' => now(),
            ])->save();

            AuditLog::query()->create([
                'organisation_id' => $lease->organisation_id,
                'actor_user_id' => $userId,
                'auditable_type' => $lease->getMorphClass(),
                'auditable_id' => $lease->id,
                'lease_id' => $lease->id,
                'event' => 'tenant_moved_out',
                'new_values' => [
                    'end_date' => $endDate->toDateString(),
                    'passkeys_removed' => $passkeys,
                    'reminders_stopped' => $reminders,
                ],
            ]);
        });

        return $lease;
    }

    /** The new tenancy reuses the earlier one's room list; nothing else carries over. */
    public function follow(Lease $next, Lease $previous): void
    {
        $areaIds = $previous
            ->coveredAreas()
            ->pluck('areas_rooms.id')
            ->map(static fn(mixed $id): int => (int) $id);

        if ($areaIds->isNotEmpty()) {
            $this->leases->setCoveredAreas($next, $areaIds->values()->all());
        }
    }
}
