<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendDeliveryJob;
use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\Inspection;
use App\Models\InspectionReport;
use App\Models\InspectionSignature;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Sends the signing link and the locked report to the parties by email, and by
 * WhatsApp where the organisation has opted in. Every send is a recorded
 * Delivery row; the queued job owns the retry policy and the failure audit.
 */
final class DeliveryService
{
    public function __construct(
        private readonly ReportLinkService $links,
        private readonly ContactService $contacts,
    ) {}

    /**
     * @return list<Delivery>
     *
     * @throws InvalidArgumentException when the party has no contact to send to
     */
    public function sendSigningLink(Inspection $inspection, string $party, string $url, ?int $userId): array
    {
        $channels = $this->contacts->channels($inspection, $party);

        if ($channels === []) {
            throw new InvalidArgumentException('The ' . $party . ' has no email address or phone number on file.');
        }

        $message = "Your {$party} signature is requested on a property inspection. Sign here: {$url}";
        $deliveries = [];

        foreach ($channels as $channel => $recipient) {
            $deliveries[] = $this->queue(
                $inspection,
                $party,
                Delivery::KIND_SIGNING_LINK,
                $channel,
                $recipient,
                'pt-sign-' . $inspection->id . '-' . $party . '-' . $channel . '-' . Str::uuid(),
                'Please sign your property inspection',
                $message,
                null,
                $userId,
            );
        }

        return $deliveries;
    }

    /**
     * Called after every lock attempt; a null report (already locked, or not yet
     * fully signed) is a no-op. Delivery trouble is recorded on the delivery
     * rows and never undoes the lock.
     */
    public function afterLock(Inspection $inspection, ?InspectionReport $report): void
    {
        if ($report === null) {
            return;
        }

        try {
            $this->sendLockedReport($inspection, $report);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Sent once, when the report locks: the PDF to email recipients, a read
     * link to everyone. A party with no usable channel is skipped and audited.
     *
     * @return list<Delivery>
     */
    public function sendLockedReport(Inspection $inspection, InspectionReport $report): array
    {
        $deliveries = [];

        foreach (InspectionSignature::PARTIES as $party) {
            $channels = $this->contacts->channels($inspection, $party);

            if ($channels === []) {
                $this->audit($inspection, 'delivery_skipped', null, [
                    'party' => $party,
                    'reason' => 'no usable channel',
                ]);

                continue;
            }

            $link = $this->links->issue($inspection, $report, null);
            $message =
                "The signed inspection report {$report->reference} is locked. Read it here: "
                . url('/r/' . $link['token'])
                . ' (link expires '
                . $link['expires_at']->toDateString()
                . ').';

            foreach ($channels as $channel => $recipient) {
                $key = 'pt-report-' . $report->id . '-' . $party . '-' . $channel;

                if (Delivery::query()->where('idempotency_key', $key)->exists()) {
                    continue;
                }

                $deliveries[] = $this->queue(
                    $inspection,
                    $party,
                    Delivery::KIND_LOCKED_REPORT,
                    $channel,
                    $recipient,
                    $key,
                    'Your signed inspection report ' . $report->reference,
                    $message,
                    $channel === Delivery::CHANNEL_MAIL ? $report->id : null,
                    null,
                );
            }
        }

        return $deliveries;
    }

    private function queue(
        Inspection $inspection,
        string $party,
        string $kind,
        string $channel,
        string $recipient,
        string $key,
        string $subject,
        string $message,
        ?int $reportId,
        ?int $userId,
    ): Delivery {
        $delivery = Delivery::query()->create([
            'organisation_id' => $inspection->organisation_id,
            'inspection_id' => $inspection->id,
            'party' => $party,
            'kind' => $kind,
            'channel' => $channel,
            'recipient' => $recipient,
            'idempotency_key' => $key,
            'status' => Delivery::STATUS_PENDING,
        ]);

        $details = ['party' => $party, 'channel' => $channel, 'kind' => $kind];
        $this->audit($inspection, 'delivery_queued', $userId, $details);

        try {
            SendDeliveryJob::dispatch($delivery->id, $subject, $message, $reportId);
        } catch (Throwable $exception) {
            report($exception);
            $delivery->forceFill([
                'status' => Delivery::STATUS_FAILED,
                'last_error' => 'Could not queue the send.',
            ])->save();
            $this->audit($inspection, 'delivery_failed', $userId, $details + ['error' => 'Could not queue the send.']);
        }

        return $delivery->refresh();
    }

    /** @param array<string, mixed> $values */
    private function audit(Inspection $inspection, string $event, ?int $userId, array $values): void
    {
        AuditLog::query()->create([
            'organisation_id' => $inspection->organisation_id,
            'actor_user_id' => $userId,
            'auditable_type' => $inspection->getMorphClass(),
            'auditable_id' => $inspection->id,
            'event' => $event,
            'new_values' => $values,
        ]);
    }
}
