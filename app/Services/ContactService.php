<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Delivery;
use App\Models\Inspection;
use App\Models\InspectionContact;
use App\Models\InspectionSignature;
use App\Models\Organisation;
use InvalidArgumentException;

/** Where each signing party can be reached, and over which channels this organisation allows. */
final class ContactService
{
    public function save(Inspection $inspection, string $party, ?string $email, ?string $phone): InspectionContact
    {
        if (!in_array($party, InspectionSignature::PARTIES, true)) {
            throw new InvalidArgumentException('Unknown signing party.');
        }

        return InspectionContact::query()->updateOrCreate(['inspection_id' => $inspection->id, 'party' => $party], [
            'organisation_id' => $inspection->organisation_id,
            'email' => $email,
            'phone' => $phone,
        ]);
    }

    public function canReach(Inspection $inspection, string $party): bool
    {
        return $this->channels($inspection, $party) !== [];
    }

    /** @return array<string, string> channel => recipient */
    public function channels(Inspection $inspection, string $party): array
    {
        $contact = InspectionContact::query()->where('inspection_id', $inspection->id)->where('party', $party)->first();

        if ($contact === null) {
            return [];
        }

        $channels = [];

        if ($contact->email !== null && $contact->email !== '') {
            $channels[Delivery::CHANNEL_MAIL] = $contact->email;
        }

        if ($contact->phone !== null && $contact->phone !== '' && $this->whatsAppEnabled($inspection)) {
            $channels[Delivery::CHANNEL_WHATSAPP] = $contact->phone;
        }

        return $channels;
    }

    private function whatsAppEnabled(Inspection $inspection): bool
    {
        $organisation = Organisation::query()->find($inspection->organisation_id);

        return ($organisation->settings['delivery']['whatsapp'] ?? false) === true;
    }
}
