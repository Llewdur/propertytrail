<?php

declare(strict_types=1);

namespace App\Contracts\Adapters;

interface PushAdapterInterface
{
    /** Whether Fleet Push is switched on; with it off nothing is called and capture is not gated. */
    public function enabled(): bool;

    /**
     * Creates a Push-hosted opt-in link for one participant.
     *
     * @return array{link_id: string, short_url: string, expires_at: string}
     */
    public function createOptInLink(string $participantReference, int $expiresInHours): array;

    /**
     * Reads an opt-in link's state.
     *
     * @return array{status: string, device_class: ?string, active: bool}
     */
    public function readOptInLink(string $linkId): array;

    /**
     * Sends one notification to every active subscription of a participant. Accepted is not delivered.
     *
     * @return array<string, mixed>
     */
    public function notifyParticipant(
        string $idempotencyKey,
        string $participantReference,
        string $title,
        string $body,
        string $url,
    ): array;
}
