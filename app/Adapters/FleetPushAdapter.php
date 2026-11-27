<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\PushAdapterInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fleet Push (standards docs/push-opt-in.md, Push docs/api.md). Push authenticates a caller with a
 * static bearer token. The opt-in page is Push's own; this app never holds VAPID keys or endpoints.
 */
final class FleetPushAdapter implements PushAdapterInterface
{
    private const CALLER_REFERENCE = 'propertytrail-capture-reminder';

    public function enabled(): bool
    {
        return (bool) config('services.push.enabled');
    }

    public function createOptInLink(string $participantReference, int $expiresInHours): array
    {
        $created = $this->request()->post($this->url('/api/v1/opt-in-links'), [
            'participant_reference' => $participantReference,
            'expires_in_hours' => $expiresInHours,
        ])->throw();
        $linkId = (string) $created->json('data.link_id', '');
        $shortUrl = (string) $created->json('data.short_url', '');

        if ($linkId === '' || $shortUrl === '') {
            throw new RuntimeException('Fleet Push opt-in link response carried no link.');
        }

        return [
            'link_id' => $linkId,
            'short_url' => $shortUrl,
            'expires_at' => (string) $created->json('data.expires_at', ''),
        ];
    }

    public function readOptInLink(string $linkId): array
    {
        $read = $this
            ->request()
            ->get($this->url('/api/v1/opt-in-links/' . rawurlencode($linkId)))
            ->throw();
        $deviceClass = $read->json('data.device_class');

        return [
            'status' => (string) $read->json('data.status', ''),
            'device_class' => is_string($deviceClass) ? $deviceClass : null,
            'active' => collect((array) $read->json('data.subscriptions', []))->contains('state', 'active'),
        ];
    }

    public function notifyParticipant(
        string $idempotencyKey,
        string $participantReference,
        string $title,
        string $body,
        string $url,
    ): array {
        return $this->request()->post($this->url('/api/v1/instructions'), [
            'idempotency_key' => $idempotencyKey,
            'caller_reference' => self::CALLER_REFERENCE,
            'participant_references' => [$participantReference],
            'payload' => ['title' => $title, 'body' => $body, 'url' => $url],
        ])->throw()->json() ?? [];
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) config('services.push.token'))
            ->acceptJson()
            ->asJson()
            ->timeout(10);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.push.url'), '/') . $path;
    }
}
