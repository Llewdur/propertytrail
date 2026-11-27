<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Adapters\MailAdapterInterface;
use App\Contracts\Adapters\WhatsAppAdapterInterface;
use App\Models\AuditLog;
use App\Models\CaptureLink;
use App\Models\Delivery;
use App\Models\Lease;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Sends a capture link by email or WhatsApp. The message carries the token, so
 * the payload is encrypted and a provider error is never logged or chained.
 */
final class SendCaptureLinkJob implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $captureLinkId,
        public readonly string $channel,
        public readonly string $recipient,
        public readonly string $message,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MailAdapterInterface $mail, WhatsAppAdapterInterface $whatsApp): void
    {
        $link = CaptureLink::query()->allOrganisations()->find($this->captureLinkId);

        if ($link === null || !$link->isLive()) {
            return;
        }

        $key = 'pt-capture-' . $link->id . '-' . $this->channel;

        try {
            match ($this->channel) {
                Delivery::CHANNEL_MAIL => $mail->send([
                    'idempotency_key' => $key,
                    'to' => $this->recipient,
                    'subject' => 'Photograph your property',
                    'text' => $this->message,
                ]),
                default => $whatsApp->sendMessage($key, $this->recipient, $this->message),
            };
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Capture link '
                . $link->id
                . ' could not be sent by '
                . $this->channel
                . ' ('
                . $exception::class
                . ').',
            );
        }

        $this->audit($link, 'capture_link_sent');
    }

    public function failed(?Throwable $exception): void
    {
        $link = CaptureLink::query()->allOrganisations()->find($this->captureLinkId);

        if ($link !== null) {
            $this->audit($link, 'capture_link_send_failed');
        }
    }

    private function audit(CaptureLink $link, string $event): void
    {
        AuditLog::query()->create([
            'organisation_id' => $link->organisation_id,
            'actor_user_id' => null,
            'auditable_type' => new Lease()->getMorphClass(),
            'auditable_id' => $link->lease_id,
            'event' => $event,
            'new_values' => ['capture_link_id' => $link->id, 'channel' => $this->channel],
        ]);
    }
}
