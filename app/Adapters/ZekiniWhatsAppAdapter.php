<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Contracts\Adapters\WhatsAppAdapterInterface;
use App\Support\FleetServiceToken;
use Illuminate\Support\Facades\Http;

/**
 * Fleet WhatsApp: POST /api/v1/instructions on the template path (a
 * business-initiated message is only allowed as an approved template, never
 * freeform outside a customer-service window). The message text is passed as
 * the template's single variable.
 */
final class ZekiniWhatsAppAdapter implements WhatsAppAdapterInterface
{
    public function sendMessage(string $idempotencyKey, string $recipient, string $message): array
    {
        $response = Http::withToken(FleetServiceToken::for('whatsapp'))
            ->timeout(15)
            ->acceptJson()
            ->post(rtrim((string) config('services.whatsapp.url'), '/') . '/api/v1/instructions', [
                'idempotency_key' => $idempotencyKey,
                'recipient' => $recipient,
                'send_path' => 'template',
                'category' => 'utility',
                'template' => [
                    'id' => config('services.whatsapp.template_id'),
                    'language' => config('services.whatsapp.template_language'),
                    'variables' => [$message],
                ],
                'provider' => [
                    'name' => config('services.whatsapp.provider'),
                    'credential_alias' => config('services.whatsapp.credential_alias'),
                ],
            ]);

        if ($response->status() === 401) {
            FleetServiceToken::forget('whatsapp');
        }

        return $response->throw()->json() ?? [];
    }
}
