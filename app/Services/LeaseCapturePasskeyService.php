<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CapturePasskey;
use App\Models\Lease;
use Illuminate\Database\Eloquent\Collection;

/** Staff side of the capture passkeys on one lease. */
final class LeaseCapturePasskeyService
{
    /** @return array<int, array{phase: string, registered_at: ?string, last_used_at: ?string}> */
    public function forLease(Lease $lease): array
    {
        $rows = [];

        /** @var Collection<int, CapturePasskey> $passkeys */
        $passkeys = CapturePasskey::query()->where('lease_id', $lease->getKey())->get()->sortBy('phase')->values();

        foreach ($passkeys as $passkey) {
            $rows[] = [
                'phase' => $passkey->phase,
                'registered_at' => $passkey->created_at?->toIso8601String(),
                'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /** Removes a lost passkey, so the link alone works again. True when there was one. */
    public function reset(Lease $lease, string $phase, ?int $userId): bool
    {
        $passkey = CapturePasskey::query()->where('lease_id', $lease->getKey())->where('phase', $phase)->first();

        if ($passkey === null) {
            return false;
        }

        $passkey->delete();
        $passkey->audit('capture_passkey_reset', $userId);

        return true;
    }
}
