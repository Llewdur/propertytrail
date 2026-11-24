<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Contracts\Adapters\AccountingAdapterInterface;
use App\Exceptions\PlanLimitExceeded;
use App\Models\BillingEventOutbox;
use App\Models\Organisation;
use App\Models\Subscription;
use App\Models\UsageRecord;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class EntitlementService
{
    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
        private readonly AccountingAdapterInterface $accounting,
    ) {}

    public function organisation(): Organisation
    {
        $organisation = Organisation::query()->find($this->currentOrganisation->id());

        if ($organisation === null) {
            throw new RuntimeException('No organisation resolved for this request.');
        }

        return $organisation;
    }

    public function planCode(Organisation $organisation): string
    {
        $settings = $organisation->settings;
        $code = is_array($settings) ? $settings['plan'] ?? null : null;
        $plans = $this->plans();

        return is_string($code) && array_key_exists($code, $plans)
            ? $code
            : (string) config('plans.default_plan', 'free');
    }

    public function plan(Organisation $organisation): array
    {
        return $this->plans()[$this->planCode($organisation)] ?? [];
    }

    public function subscription(Organisation $organisation): ?Subscription
    {
        return Subscription::query()
            ->withoutGlobalScope('organisation')
            ->where('organisation_id', $organisation->id)
            ->latest('starts_at')
            ->first();
    }

    public function status(Organisation $organisation): string
    {
        $subscription = $this->subscription($organisation);

        return $subscription->status ?? Subscription::STATUS_ACTIVE;
    }

    public function assertCan(string $action, Organisation $organisation): void
    {
        $plan = $this->plan($organisation);
        $kind = match ($action) {
            'inspection' => UsageRecord::KIND_INSPECTION,
            'property' => UsageRecord::KIND_PROPERTY,
            'signing_link' => UsageRecord::KIND_SIGNING_LINK,
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $limitKey = match ($kind) {
            UsageRecord::KIND_INSPECTION => 'inspection_allowance',
            UsageRecord::KIND_PROPERTY => 'property_limit',
            UsageRecord::KIND_SIGNING_LINK => 'signing_link_limit',
        };

        $limit = $plan[$limitKey] ?? null;
        if (!is_int($limit)) {
            return;
        }

        $period = $kind === UsageRecord::KIND_SIGNING_LINK ? Carbon::now()->startOfMonth()->toDateString() : null;

        if ($this->usageCount($organisation, $kind, $period) < $limit) {
            return;
        }

        [$nextCode, $nextPlan] = $this->nextPlan($this->planCode($organisation));
        $currency = (string) config('plans.currency', 'ZAR');
        $price = $nextCode === null
            ? 'Contact us'
            : $currency . ' ' . number_format((int) ($nextPlan['price_monthly_cents'] ?? 0) / 100, 0);
        $nextPlanName = $nextCode === null ? 'a higher tier' : (string) ($nextPlan['name'] ?? $nextCode);
        $message = sprintf(
            'Your %s plan allows %d %s. Upgrade to %s (%s/month) to continue.',
            (string) ($plan['name'] ?? $this->planCode($organisation)),
            $limit,
            str_replace('_', ' ', $kind),
            $nextPlanName,
            $price,
        );

        throw new PlanLimitExceeded(
            $message,
            $this->planCode($organisation),
            $nextCode ?? '',
            (int) ($nextPlan['price_monthly_cents'] ?? 0),
        );
    }

    public function usageCount(Organisation $organisation, string $kind, ?string $periodStart = null): int
    {
        /** @var Builder<UsageRecord> $query */
        $query = UsageRecord::query()
            ->withoutGlobalScope('organisation')
            ->where('organisation_id', $organisation->id)
            ->where('kind', $kind);

        if ($periodStart !== null) {
            $query->where('period_start', $periodStart);
        }

        return $query->count();
    }

    public function recordUsage(string $kind, Organisation $organisation, int $subjectId, string $subjectType): void
    {
        $periodStart = Carbon::now()->startOfMonth()->toDateString();
        $key = [
            'organisation_id' => $organisation->id,
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ];

        $created = UsageRecord::query()
            ->withoutGlobalScope('organisation')
            ->where('organisation_id', $organisation->id)
            ->firstOrCreate($key, [
                'period_start' => $periodStart,
            ]);

        if (!$created->wasRecentlyCreated) {
            return;
        }

        $reference = 'propertytrail:' . $organisation->uuid . ':' . $kind . ':' . $subjectId;

        $this->dispatch($organisation, 'usage_recorded', $reference, [
            'usage' => ['metric' => $kind, 'quantity' => 1],
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'organisation_uuid' => $organisation->uuid,
        ]);
    }

    public function recordReportUsage(mixed $organisation, int $inspectionId): void
    {
        if (!$organisation instanceof Organisation) {
            return;
        }

        if (($this->plan($organisation)['watermark'] ?? true) === false) {
            $this->recordUsage(UsageRecord::KIND_UNWATERMARKED_EXPORT, $organisation, $inspectionId, 'inspection');
        }
    }

    public function summary(Organisation $organisation): array
    {
        $plan = $this->plan($organisation);
        $subscription = $this->subscription($organisation);

        return [
            'plan' => [
                'code' => $this->planCode($organisation),
                'name' => $plan['name'] ?? $this->planCode($organisation),
            ],
            'status' => $subscription->status ?? Subscription::STATUS_ACTIVE,
            'limits' => [
                'inspections' => $plan['inspection_allowance'] ?? null,
                'properties' => $plan['property_limit'] ?? null,
                'signing_links_per_month' => $plan['signing_link_limit'] ?? null,
                'watermark' => (bool) ($plan['watermark'] ?? true),
                'custom_logo' => (bool) ($plan['custom_logo'] ?? false),
            ],
            'usage' => [
                'inspections' => $this->usageCount($organisation, UsageRecord::KIND_INSPECTION),
                'properties' => $this->usageCount($organisation, UsageRecord::KIND_PROPERTY),
                'signing_links_this_month' => $this->usageCount(
                    $organisation,
                    UsageRecord::KIND_SIGNING_LINK,
                    Carbon::now()->startOfMonth()->toDateString(),
                ),
            ],
            'plans' => collect($this->plans())
                ->map(static fn(array $plan, string $code): array => ['code' => $code] + $plan)
                ->values()
                ->all(),
        ];
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = config('services.accounting.webhook_secret');
        if (!is_string($secret) || $secret === '' || $signature === null) {
            return false;
        }

        $signature = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function applySubscriptionEvent(array $payload): void
    {
        $rawData = $payload['data'] ?? [];
        $data = is_array($rawData) ? $rawData : [];
        $rawMetadata = $data['metadata'] ?? [];
        $metadata = is_array($rawMetadata) ? $rawMetadata : [];
        $eventType = (string) ($payload['event_type'] ?? '');
        $reference = (string) ($data['external_reference'] ?? $metadata['external_reference'] ?? '');
        $uuid = $data['organisation_uuid'] ?? $metadata['organisation_uuid'] ?? null;

        if ($reference !== '' && str_starts_with($reference, 'propertytrail:')) {
            $uuid = substr($reference, strlen('propertytrail:'));
        }

        if (!is_string($uuid) || $uuid === '') {
            return;
        }

        $organisation = Organisation::query()->where('uuid', $uuid)->first();
        if (!$organisation instanceof Organisation) {
            return;
        }

        $rawPlan = $data['plan'] ?? null;
        $rawPlanCode =
            $data['plan_code'] ?? $metadata['plan_code'] ?? (is_array($rawPlan) ? $rawPlan['code'] ?? null : null);
        $planCode = is_string($rawPlanCode) ? $rawPlanCode : $this->planCode($organisation);
        if (!array_key_exists($planCode, $this->plans())) {
            $planCode = $this->planCode($organisation);
        }

        $suspends = in_array(
            $eventType,
            [
                'subscription_cancelled',
                'subscription_past_due',
                'payment_failed',
            ],
            true,
        );
        $reactivates = in_array($eventType, ['payment_received', 'customer_upgraded'], true);

        $subscription = $this->subscription($organisation);
        $status = match (true) {
            $suspends => Subscription::STATUS_SUSPENDED,
            $reactivates || $subscription === null => Subscription::STATUS_ACTIVE,
            default => $subscription->status,
        };

        if ($subscription === null) {
            $subscription = new Subscription([
                'organisation_id' => $organisation->id,
                'plan_code' => $planCode,
                'status' => $status,
                'starts_at' => Carbon::now(),
            ]);
        }

        $subscription->plan_code = $planCode;
        $subscription->status = $status;
        if (array_key_exists('customer_id', $data) && is_scalar($data['customer_id'])) {
            $subscription->external_customer_id = (string) $data['customer_id'];
        }
        if (array_key_exists('subscription_id', $data) && is_scalar($data['subscription_id'])) {
            $subscription->external_subscription_id = (string) $data['subscription_id'];
        }
        $subscription->save();

        $settings = is_array($organisation->settings) ? $organisation->settings : [];
        $settings['plan'] = $suspends ? (string) config('plans.default_plan', 'free') : $subscription->plan_code;
        $organisation->settings = $settings;
        $organisation->save();
    }

    public function ensureCustomer(Organisation $organisation): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $existing = $organisation->accounting_customer_id;
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $email = config('services.accounting.customer_email');
        if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        try {
            $response = $this->accounting->createCustomer([
                'display_name' => $organisation->name,
                'external_reference' => 'propertytrail:organisation:' . $organisation->uuid,
                'primary_contact_email' => $email,
            ]);
        } catch (Throwable $exception) {
            Log::warning('FAC customer create failed', [
                'organisation_id' => $organisation->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        $data = $response['data'] ?? null;
        $id = is_array($data) ? $data['id'] ?? null : $response['id'] ?? null;
        if (!is_scalar($id) || (string) $id === '') {
            return null;
        }

        $customerId = (string) $id;
        $organisation->accounting_customer_id = $customerId;
        $organisation->save();

        return $customerId;
    }

    public function flushOutbox(int $limit = 20): int
    {
        /** @var array<int, BillingEventOutbox> $rows */
        $rows = BillingEventOutbox::query()
            ->withoutGlobalScope('organisation')
            ->whereIn('status', [BillingEventOutbox::STATUS_PENDING, BillingEventOutbox::STATUS_FAILED])
            ->where(static function (Builder $query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', Carbon::now());
            })
            ->limit($limit)
            ->getModels();

        $sent = 0;
        foreach ($rows as $row) {
            $organisation = Organisation::query()->find($row->organisation_id);
            if ($organisation === null) {
                continue;
            }

            try {
                $this->accounting->recordEvent($row->event_type, $row->external_reference_id, $row->payload);
                $row->status = BillingEventOutbox::STATUS_SENT;
                $row->last_error = null;
                $row->next_attempt_at = null;
                $sent++;
            } catch (Throwable $exception) {
                $row->status = BillingEventOutbox::STATUS_FAILED;
                $row->last_error = $exception->getMessage();
                $row->next_attempt_at = Carbon::now()->addMinutes(5 * ((int) $row->attempts + 1));
            }

            $row->attempts = (int) $row->attempts + 1;
            $row->save();
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(Organisation $organisation, string $eventType, string $reference, array $payload): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $this->ensureCustomer($organisation);

        try {
            $this->accounting->recordEvent($eventType, $reference, $payload);
        } catch (Throwable $exception) {
            Log::warning('FAC event queued for retry', [
                'organisation_id' => $organisation->id,
                'reference' => $reference,
                'error' => $exception->getMessage(),
            ]);

            BillingEventOutbox::query()
                ->withoutGlobalScope('organisation')
                ->where('organisation_id', $organisation->id)
                ->firstOrCreate(['external_reference_id' => $reference], [
                    'event_type' => $eventType,
                    'payload' => $payload,
                    'status' => BillingEventOutbox::STATUS_PENDING,
                    'next_attempt_at' => Carbon::now()->addMinutes(5),
                ]);
        }
    }

    private function isConfigured(): bool
    {
        return (
            is_string(config('services.accounting.client_id'))
            && config('services.accounting.client_id') !== ''
            && is_string(config('services.accounting.client_secret'))
            && config('services.accounting.client_secret') !== ''
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function plans(): array
    {
        $configured = config('plans.plans');
        $plans = [];

        if (!is_array($configured)) {
            return $plans;
        }

        foreach ($configured as $code => $plan) {
            if (!is_string($code) || !is_array($plan)) {
                continue;
            }

            /** @var array<string, mixed> $plan */
            $plans[$code] = $plan;
        }

        return $plans;
    }

    /** @return array{0: ?string, 1: array<string, mixed>} */
    private function nextPlan(string $currentCode): array
    {
        $plans = $this->plans();
        $codes = array_keys($plans);
        $index = array_search($currentCode, $codes, true);
        if ($index === false) {
            $index = 0;
        }

        for ($position = $index + 1; $position < count($codes); $position++) {
            $code = $codes[$position];
            $plan = $plans[$code] ?? [];
            if ((int) ($plan['price_monthly_cents'] ?? 0) > 0) {
                return [$code, $plan];
            }
        }

        return [null, []];
    }
}
