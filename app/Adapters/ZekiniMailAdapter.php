<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\MailAdapterInterface;
use App\Support\FleetServiceToken;
use Illuminate\Support\Facades\Http;

/**
 * Fleet Mail: POST /api/v1/instructions (a self-contained send instruction),
 * machine bearer token. The caller passes the message; sender, provider and
 * bounce policy come from config.
 */
final class ZekiniMailAdapter implements MailAdapterInterface
{
    /**
     * @param  array{idempotency_key: string, to: string, subject: string, text: string, html?: string, attachments?: list<array{filename: string, content_type: string, content: string}>}  $payload
     * @return array<string, mixed>
     */
    public function send(array $payload): array
    {
        $instruction = [
            'idempotency_key' => $payload['idempotency_key'],
            'sender' => [
                'address' => config('services.mail.sender_address'),
                'name' => config('services.mail.sender_name'),
            ],
            'recipients' => [['address' => $payload['to']]],
            'subject' => $payload['subject'],
            'body' => array_filter(['text' => $payload['text'], 'html' => $payload['html'] ?? null]),
            'provider' => [
                'name' => config('services.mail.provider'),
                'credential_ref' => config('services.mail.credential_ref'),
            ],
            'bounce_policy' => ['on_hard_bounce' => 'suppress', 'on_complaint' => 'suppress'],
        ];

        if (($payload['attachments'] ?? []) !== []) {
            $instruction['attachments'] = $payload['attachments'];
        }

        $response = Http::withToken(FleetServiceToken::for('mail'))
            ->timeout(15)
            ->acceptJson()
            ->post(rtrim((string) config('services.mail.url'), '/') . '/api/v1/instructions', $instruction);

        if ($response->status() === 401) {
            FleetServiceToken::forget('mail');
        }

        return $response->throw()->json() ?? [];
    }
}
