<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Adapters\MailAdapterInterface;
use App\Contracts\Adapters\WhatsAppAdapterInterface;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\Inspection;
use App\Models\InspectionReport;
use App\Services\ReportService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * One delivery attempt. Retry policy lives here, in one place: up to
 * Delivery::MAX_ATTEMPTS tries with a growing delay, then the delivery is
 * marked failed and an audit event is written. Nothing is dropped silently.
 * The idempotency key is the delivery's own, so a retry can never double-send.
 */
final class SendDeliveryJob implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    /** Seconds to wait before attempt 2, 3. */
    private const BACKOFF = [30, 120];

    public function __construct(
        public readonly int $deliveryId,
        public readonly string $subject,
        public readonly string $message,
        public readonly ?int $reportId = null,
    ) {}

    public function handle(MailAdapterInterface $mail, WhatsAppAdapterInterface $whatsApp, ReportService $reports): void
    {
        $delivery = Delivery::query()->allOrganisations()->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== Delivery::STATUS_PENDING) {
            return;
        }

        $delivery->increment('attempts');

        try {
            match ($delivery->channel) {
                Delivery::CHANNEL_MAIL => $mail->send($this->mailPayload($delivery, $reports)),
                default => $whatsApp->sendMessage($delivery->idempotency_key, $delivery->recipient, $this->message),
            };
        } catch (Throwable $exception) {
            $this->recordFailure($delivery, $exception);

            return;
        }

        $delivery->forceFill(['status' => Delivery::STATUS_SENT, 'sent_at' => now(), 'last_error' => null])->save();
        $this->audit($delivery, 'delivery_sent', ['channel' => $delivery->channel, 'kind' => $delivery->kind]);
    }

    /** @return array<string, mixed> */
    private function mailPayload(Delivery $delivery, ReportService $reports): array
    {
        $payload = [
            'idempotency_key' => $delivery->idempotency_key,
            'to' => $delivery->recipient,
            'subject' => $this->subject,
            'text' => $this->message,
        ];

        $report = $this->reportId === null
            ? null
            : InspectionReport::query()->allOrganisations()->find($this->reportId);
        $bytes = $report === null ? null : $reports->bytes($report);

        if ($this->reportId !== null && ($report === null || $bytes === null)) {
            throw new RuntimeException('The locked report PDF could not be loaded for attachment.');
        }

        if ($report !== null && $bytes !== null) {
            $payload['attachments'] = [[
                'filename' => $report->reference . '.pdf',
                'content_type' => 'application/pdf',
                'content' => base64_encode($bytes),
            ]];
        }

        return $payload;
    }

    private function recordFailure(Delivery $delivery, Throwable $exception): void
    {
        $delivery->refresh();
        $error = mb_substr($exception->getMessage(), 0, 500);

        if ($delivery->attempts < Delivery::MAX_ATTEMPTS) {
            $delivery->forceFill(['last_error' => $error])->save();
            self::dispatch($this->deliveryId, $this->subject, $this->message, $this->reportId)
                ->delay(now()->addSeconds(self::BACKOFF[$delivery->attempts - 1] ?? 120));

            return;
        }

        $delivery->forceFill(['status' => Delivery::STATUS_FAILED, 'last_error' => $error])->save();
        $this->audit($delivery, 'delivery_failed', [
            'channel' => $delivery->channel,
            'kind' => $delivery->kind,
            'attempts' => $delivery->attempts,
            'error' => $error,
        ]);
        report($exception);
    }

    /** @param array<string, mixed> $values */
    private function audit(Delivery $delivery, string $event, array $values): void
    {
        AuditLog::query()->create([
            'organisation_id' => $delivery->organisation_id,
            'actor_user_id' => null,
            'auditable_type' => new Inspection()->getMorphClass(),
            'auditable_id' => $delivery->inspection_id,
            'event' => $event,
            'new_values' => $values + ['party' => $delivery->party],
        ]);
    }
}
