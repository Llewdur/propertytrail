<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\InspectionReport;
use App\Models\ReportAccessLog;
use App\Models\ReportLink;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Support\Str;

/** The expiring, hashed, access-logged public tokens that back the account-less /r/{token} read view. */
final class ReportLinkService
{
    public const LINK_TTL_DAYS = 30;

    public const OUTCOME_OK = 'ok';
    public const OUTCOME_UNKNOWN = 'unknown';
    public const OUTCOME_EXPIRED = 'expired';
    public const OUTCOME_REVOKED = 'revoked';

    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
    ) {}

    /** @return array{token: string, expires_at: \Illuminate\Support\Carbon} */
    public function issue(Inspection $inspection, InspectionReport $report, ?int $userId): array
    {
        $plain = Str::random(48);
        $link = ReportLink::query()->create([
            'organisation_id' => $report->organisation_id,
            'inspection_report_id' => $report->id,
            'token_hash' => ReportLink::hash($plain),
            'expires_at' => now()->addDays(self::LINK_TTL_DAYS),
        ]);

        AuditLog::query()->create([
            'organisation_id' => $inspection->organisation_id,
            'actor_user_id' => $userId,
            'auditable_type' => $inspection->getMorphClass(),
            'auditable_id' => $inspection->id,
            'event' => 'report_link_issued',
            'new_values' => ['expires_at' => $link->expires_at->toIso8601String()],
        ]);

        return ['token' => $plain, 'expires_at' => $link->expires_at];
    }

    public function revokeAll(InspectionReport $report): int
    {
        return ReportLink::query()
            ->where('inspection_report_id', $report->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Looks a public link up and logs the attempt. On success the request is
     * bound to the link's organisation, derived server-side from the hash.
     *
     * @return array{link: ?ReportLink, outcome: string}
     */
    public function resolve(#[\SensitiveParameter] string $plainToken, ?string $ip): array
    {
        $link = ReportLink::query()->allOrganisations()->where('token_hash', ReportLink::hash($plainToken))->first();

        $outcome = match (true) {
            $link === null => self::OUTCOME_UNKNOWN,
            $link->revoked_at !== null => self::OUTCOME_REVOKED,
            $link->expires_at->isPast() => self::OUTCOME_EXPIRED,
            default => self::OUTCOME_OK,
        };

        ReportAccessLog::query()->create([
            'report_link_id' => $link?->id,
            'organisation_id' => $link?->organisation_id,
            'outcome' => $outcome,
            'ip' => $ip,
        ]);

        if ($outcome === self::OUTCOME_OK && $link !== null) {
            $this->currentOrganisation->set($link->organisation_id);
        }

        return ['link' => $outcome === self::OUTCOME_OK ? $link : null, 'outcome' => $outcome];
    }
}
