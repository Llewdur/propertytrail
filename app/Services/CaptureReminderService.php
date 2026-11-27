<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Adapters\PushAdapterInterface;
use App\Contracts\Adapters\WhatsAppAdapterInterface;
use App\Models\CaptureLink;
use App\Models\CaptureReminder;
use App\Models\CaptureSignOff;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Reminds tenants who turned on notifications (or chose WhatsApp) until they sign off. At most one a day,
 * never in quiet hours, seven at most. A send accepted by Push or WhatsApp is not proof of delivery.
 */
final class CaptureReminderService
{
    public const INTERVAL_HOURS = 24;

    public const MAX_REMINDERS = 7;

    public const TIMEZONE = 'Africa/Johannesburg';

    /** Reminders go out only from 08:00 to 19:00 local time. */
    public const SEND_FROM_HOUR = 8;

    public const SEND_UNTIL_HOUR = 19;

    public function __construct(
        private readonly PushAdapterInterface $push,
        private readonly WhatsAppAdapterInterface $whatsApp,
    ) {}

    /** Sends every reminder that is due now. Returns how many were sent. */
    public function sendDue(): int
    {
        $local = now()->setTimezone(self::TIMEZONE);

        if ($local->hour < self::SEND_FROM_HOUR || $local->hour >= self::SEND_UNTIL_HOUR) {
            return 0;
        }

        $sent = 0;

        CaptureReminder::query()
            ->allOrganisations()
            ->whereNull('stopped_at')
            ->where(static fn($query) => $query->whereNotNull('push_subscribed_at')->orWhereNotNull('fallback_at'))
            ->orderBy('id')
            ->each(function (CaptureReminder $reminder) use (&$sent): void {
                $sent += $this->remind($reminder) ? 1 : 0;
            });

        return $sent;
    }

    private function remind(CaptureReminder $reminder): bool
    {
        $link = CaptureLink::query()
            ->allOrganisations()
            ->where('lease_id', $reminder->lease_id)
            ->where('phase', $reminder->phase)
            ->latest('id')
            ->first();

        $stop = $this->stopReason($reminder, $link);

        if ($stop !== null) {
            $reminder->forceFill(['stopped_at' => now(), 'stop_reason' => $stop])->save();
            $reminder->audit('capture_reminders_stopped', ['reason' => $stop]);

            return false;
        }

        /** @var CaptureLink $link */
        if ($this->lastContact($reminder, $link)->greaterThan(now()->subHours(self::INTERVAL_HOURS))) {
            return false;
        }

        $number = $reminder->reminders_sent + 1;
        $channel = $reminder->push_subscribed_at !== null ? 'push' : 'whatsapp';

        try {
            $this->send($reminder, $channel, 'ptr-capture-reminder-' . $reminder->id . '-' . $number, $link);
        } catch (Throwable $exception) {
            // Only the class: the message carries the tenant's link.
            $reminder->audit('capture_reminder_failed', [
                'channel' => $channel,
                'number' => $number,
                'error' => $exception::class,
            ]);

            return false;
        }

        $reminder->forceFill(['reminders_sent' => $number, 'last_reminded_at' => now()])->save();
        $reminder->audit('capture_reminder_sent', ['channel' => $channel, 'number' => $number]);

        return true;
    }

    private function stopReason(CaptureReminder $reminder, ?CaptureLink $link): ?string
    {
        $signed = CaptureSignOff::query()
            ->allOrganisations()
            ->where('lease_id', $reminder->lease_id)
            ->where('phase', $reminder->phase)
            ->exists();

        return match (true) {
            $signed => 'complete',
            $link === null || !$link->isLive() || $link->token_encrypted === null => 'link_ended',
            $reminder->reminders_sent >= self::MAX_REMINDERS => 'limit',
            default => null,
        };
    }

    /** The clock restarts on the tenant's own visit, the last reminder, or the moment they opted in. */
    private function lastContact(CaptureReminder $reminder, CaptureLink $link): Carbon
    {
        return max(
            $reminder->last_reminded_at ?? $reminder->created_at,
            $link->last_used_at ?? $reminder->created_at,
            $reminder->push_subscribed_at ?? $reminder->fallback_at ?? $reminder->created_at,
        );
    }

    private function send(CaptureReminder $reminder, string $channel, string $key, CaptureLink $link): void
    {
        $url = CaptureLinkService::url((string) $link->token_encrypted);

        if ($channel === 'whatsapp') {
            $this->whatsApp->sendMessage(
                $key,
                (string) $reminder->fallback_recipient,
                'Please finish photographing your property: ' . $url,
            );

            return;
        }

        $this->push->notifyParticipant(
            $key,
            $reminder->participantReference(),
            'Finish photographing your property',
            'Tap to carry on where you stopped.',
            $url,
        );
    }
}
