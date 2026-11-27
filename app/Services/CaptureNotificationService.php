<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Adapters\PushAdapterInterface;
use App\Models\CaptureLink;
use App\Models\CaptureReminder;
use App\Models\Lease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tenants turn on notifications through a Fleet Push hosted opt-in page before they capture,
 * or give a WhatsApp number when they cannot. With Fleet Push off, capture is not gated.
 */
final class CaptureNotificationService
{
    public const STATUS_OFF = 'off';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUBSCRIBED = 'subscribed';

    public const STATUS_FALLBACK = 'fallback';

    /** Push caps an opt-in link at 30 days. */
    private const MAX_LINK_HOURS = 720;

    public function __construct(
        private readonly PushAdapterInterface $push,
    ) {}

    /**
     * The phone's notification step. While pending, Push is asked whether the tenant has finished opting in.
     *
     * @return array{required: bool, status: string, opt_in_url: ?string}
     */
    public function state(CaptureLink $link): array
    {
        if (!$this->push->enabled()) {
            return ['required' => false, 'status' => self::STATUS_OFF, 'opt_in_url' => null];
        }

        $reminder = $this->reminder($link);

        if (!$reminder->isCleared() && $reminder->push_link_id !== null) {
            $this->refresh($reminder);
        }

        $linkUsable = $reminder->push_link_id !== null && $reminder->push_link_expires_at?->isFuture() === true;

        if (!$reminder->isCleared() && !$linkUsable) {
            $this->openLink($reminder, $link);
        }

        return [
            'required' => true,
            'status' => $this->status($reminder),
            'opt_in_url' => $reminder->isCleared() ? null : $reminder->push_opt_in_url,
        ];
    }

    /** Capture may go ahead: Push is off, or the tenant subscribed or chose WhatsApp. */
    public function cleared(CaptureLink $link): bool
    {
        return !$this->push->enabled() || CaptureReminder::findFor($link)?->isCleared() === true;
    }

    /** The tenant could not turn notifications on: remind them on WhatsApp instead. */
    public function fallback(CaptureLink $link, #[\SensitiveParameter] string $phone): void
    {
        $reminder = $this->reminder($link);

        if ($reminder->isCleared()) {
            return;
        }

        $reminder->forceFill(['fallback_recipient' => $phone, 'fallback_at' => now()])->save();
        $reminder->audit('capture_reminders_by_whatsapp', []);
    }

    private function refresh(CaptureReminder $reminder): void
    {
        try {
            $read = $this->push->readOptInLink((string) $reminder->push_link_id);
        } catch (Throwable) {
            return;
        }

        if ($read['status'] !== 'completed' || !$read['active']) {
            return;
        }

        $reminder->forceFill(['push_subscribed_at' => now(), 'push_device_class' => $read['device_class']])->save();
        $reminder->audit('capture_notifications_on', ['device_class' => $read['device_class']]);
    }

    private function openLink(CaptureReminder $reminder, CaptureLink $link): void
    {
        $hours = (int) max(1, min(self::MAX_LINK_HOURS, ceil(now()->diffInHours($link->expires_at, true))));

        try {
            $created = $this->push->createOptInLink($reminder->participantReference(), $hours);
        } catch (Throwable) {
            return;
        }

        $reminder->forceFill([
            'push_link_id' => $created['link_id'],
            'push_opt_in_url' => $created['short_url'],
            'push_link_expires_at' => $created['expires_at'] !== ''
                ? Carbon::parse($created['expires_at'])
                : now()->addHours($hours),
        ])->save();
    }

    private function status(CaptureReminder $reminder): string
    {
        return match (true) {
            $reminder->push_subscribed_at !== null => self::STATUS_SUBSCRIBED,
            $reminder->fallback_at !== null => self::STATUS_FALLBACK,
            default => self::STATUS_PENDING,
        };
    }

    private function reminder(CaptureLink $link): CaptureReminder
    {
        return DB::transaction(static function () use ($link): CaptureReminder {
            Lease::query()->lockForUpdate()->find($link->lease_id);

            return (
                CaptureReminder::findFor($link) ?? CaptureReminder::query()->create([
                    'organisation_id' => $link->organisation_id,
                    'lease_id' => $link->lease_id,
                    'phase' => $link->phase,
                ])
            );
        });
    }
}
