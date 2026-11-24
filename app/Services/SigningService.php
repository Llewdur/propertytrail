<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Inspection;
use App\Models\InspectionSignature;
use App\Models\SigningAccessLog;
use App\Models\SigningToken;
use App\Support\Tenancy\CurrentOrganisation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SigningService
{
    public const LINK_TTL_DAYS = 7;

    public const OUTCOME_OK = 'ok';
    public const OUTCOME_UNKNOWN = 'unknown';
    public const OUTCOME_EXPIRED = 'expired';
    public const OUTCOME_REVOKED = 'revoked';
    public const OUTCOME_USED = 'used';
    public const OUTCOME_SIGNED = 'signed';

    public function __construct(
        private readonly CurrentOrganisation $currentOrganisation,
        private readonly ReportService $reports,
    ) {}

    /**
     * Issues a fresh single-use link for one party, revoking any earlier live
     * link for that party. The plain token is returned once and never stored.
     *
     * @return array{token: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function issueLink(Inspection $inspection, string $party): array
    {
        $this->assertSignable($inspection, $party);

        if (InspectionSignature::query()->where('inspection_id', $inspection->id)->where('party', $party)->exists()) {
            throw new InvalidArgumentException('The ' . $party . ' has already signed this inspection.');
        }

        return DB::transaction(static function () use ($inspection, $party): array {
            SigningToken::query()
                ->where('inspection_id', $inspection->id)
                ->where('party', $party)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $plain = Str::random(48);
            $record = SigningToken::query()->create([
                'organisation_id' => $inspection->organisation_id,
                'inspection_id' => $inspection->id,
                'party' => $party,
                'token_hash' => SigningToken::hash($plain),
                'expires_at' => now()->addDays(self::LINK_TTL_DAYS),
            ]);

            return ['token' => $plain, 'expires_at' => $record->expires_at];
        });
    }

    public function revokeLinks(Inspection $inspection, string $party): int
    {
        return SigningToken::query()
            ->where('inspection_id', $inspection->id)
            ->where('party', $party)
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    /**
     * Looks a public link up and logs the attempt. On success the request is
     * bound to the token's organisation, derived server-side from the hash.
     *
     * @return array{token: ?SigningToken, outcome: string}
     */
    public function resolveLink(#[\SensitiveParameter] string $plainToken, ?string $ip): array
    {
        $token = SigningToken::query()
            ->allOrganisations()
            ->where('token_hash', SigningToken::hash($plainToken))
            ->first();

        $outcome = match (true) {
            $token === null => self::OUTCOME_UNKNOWN,
            $token->revoked_at !== null => self::OUTCOME_REVOKED,
            $token->used_at !== null => self::OUTCOME_USED,
            $token->expires_at->isPast() => self::OUTCOME_EXPIRED,
            default => self::OUTCOME_OK,
        };

        $this->logAccess($token, $outcome, $ip);

        if ($outcome === self::OUTCOME_OK && $token !== null) {
            $this->currentOrganisation->set($token->organisation_id);
        }

        return ['token' => $outcome === self::OUTCOME_OK ? $token : null, 'outcome' => $outcome];
    }

    public function signByLink(
        #[\SensitiveParameter]
        SigningToken $token,
        string $signerName,
        #[\SensitiveParameter]
        string $image,
        ?string $ip,
    ): InspectionSignature {
        \App\Support\SignatureImage::assertValid($image);

        $signature = DB::transaction(function () use ($token, $signerName, $image, $ip): InspectionSignature {
            $claimed = SigningToken::query()
                ->whereKey($token->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->update(['used_at' => now()]);

            if ($claimed !== 1) {
                throw new InvalidArgumentException('This signing link can no longer be used.');
            }

            $inspection = Inspection::query()->findOrFail($token->inspection_id);

            $signature = $this->store(
                $inspection,
                $token->party,
                $signerName,
                $image,
                InspectionSignature::VIA_LINK,
                null,
                $ip,
            );
            $this->logAccess($token, self::OUTCOME_SIGNED, $ip);

            return $signature;
        });

        $this->lockReport($signature->inspection()->firstOrFail());

        return $signature;
    }

    public function signInApp(
        Inspection $inspection,
        string $party,
        string $signerName,
        #[\SensitiveParameter]
        string $image,
        int $userId,
        ?string $ip,
    ): InspectionSignature {
        $this->assertSignable($inspection, $party);
        \App\Support\SignatureImage::assertValid($image);

        $signature = DB::transaction(
            fn(): InspectionSignature => $this->store(
                $inspection,
                $party,
                $signerName,
                $image,
                InspectionSignature::VIA_APP,
                $userId,
                $ip,
            ),
        );

        $this->lockReport($inspection);

        return $signature;
    }

    /** A report failure must never undo a saved signature; the lock is retried when the report is next requested. */
    private function lockReport(Inspection $inspection): void
    {
        try {
            $this->reports->lockWhenFullySigned($inspection);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function store(
        Inspection $inspection,
        string $party,
        string $signerName,
        string $image,
        string $via,
        ?int $userId,
        ?string $ip,
    ): InspectionSignature {
        $this->assertSignable($inspection, $party);

        $locked = Inspection::query()->lockForUpdate()->find($inspection->id);

        if (
            $locked === null
            || InspectionSignature::query()->where('inspection_id', $inspection->id)->where('party', $party)->exists()
        ) {
            throw new InvalidArgumentException('The ' . $party . ' has already signed this inspection.');
        }

        $signature = InspectionSignature::query()->create([
            'organisation_id' => $inspection->organisation_id,
            'inspection_id' => $inspection->id,
            'party' => $party,
            'signer_name' => $signerName,
            'signature_image' => $image,
            'signed_via' => $via,
            'signed_by_user_id' => $userId,
            'signer_ip' => $ip,
            'signed_at' => now(),
        ]);

        AuditLog::query()->create([
            'organisation_id' => $inspection->organisation_id,
            'actor_user_id' => $userId,
            'auditable_type' => $inspection->getMorphClass(),
            'auditable_id' => $inspection->id,
            'event' => 'signed',
            'new_values' => [
                'party' => $party,
                'signer_name' => $signerName,
                'signed_via' => $via,
                'signed_at' => $signature->signed_at->toIso8601String(),
            ],
        ]);

        return $signature;
    }

    private function assertSignable(Inspection $inspection, string $party): void
    {
        if (!in_array($party, InspectionSignature::PARTIES, true)) {
            throw new InvalidArgumentException('Unknown signing party.');
        }

        if ($inspection->isDraft()) {
            throw new InvalidArgumentException('An inspection must be submitted before it can be signed.');
        }
    }

    private function logAccess(#[\SensitiveParameter] ?SigningToken $token, string $outcome, ?string $ip): void
    {
        SigningAccessLog::query()->create([
            'signing_token_id' => $token?->id,
            'organisation_id' => $token?->organisation_id,
            'outcome' => $outcome,
            'ip' => $ip,
        ]);
    }
}
