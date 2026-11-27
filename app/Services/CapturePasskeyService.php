<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaptureLink;
use App\Models\CapturePasskey;
use App\Models\Lease;
use App\Models\Property;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;

/**
 * The optional phone unlock on a capture link. Once a passkey is set up for a lease
 * and phase, that capture's photos and answers are sent only after an unlock. A
 * phone without one keeps using the link alone; staff can reset a lost passkey.
 */
final class CapturePasskeyService
{
    /** Header carrying the unlock the phone was given; never the path or query. */
    public const UNLOCK_HEADER = 'X-Capture-Unlock';

    /** How long a ceremony's challenge stays valid on the server. */
    private const CHALLENGE_SECONDS = 300;

    /** How long one unlock lasts before the phone asks again. */
    private const UNLOCK_SECONDS = 43_200;

    /** Only the unlock's hash is held, keyed to the link it opens. */
    private const UNLOCK_CACHE = 'capture-unlock:';

    public function __construct(
        private readonly CapturePasskeyCeremony $ceremony,
    ) {}

    public function registered(CaptureLink $link): bool
    {
        return $this->passkey($link) !== null;
    }

    public function unlocked(CaptureLink $link, string $unlock): bool
    {
        if (!$this->registered($link)) {
            return true;
        }

        return $unlock !== '' && Cache::get(self::UNLOCK_CACHE . hash('sha256', $unlock)) === $link->id;
    }

    /**
     * Options for the phone to create a passkey, or null when the capture already has one.
     *
     * @return array<array-key, mixed>|null
     */
    public function registrationOptions(CaptureLink $link): ?array
    {
        if ($this->registered($link)) {
            return null;
        }

        $lease = Lease::query()->findOrFail($link->lease_id);
        $property = (string) Property::query()->findOrFail($lease->property_id)->getAttribute('name');
        $issued = $this->ceremony->creationOptions(
            random_bytes(32),
            Str::limit($property . ' · ' . $link->phase . ' photos', 64, ''),
        );
        Cache::put('capture-passkey:register:' . $link->id, $issued['options'], self::CHALLENGE_SECONDS);

        return $issued['browser'];
    }

    /**
     * Saves the phone's new passkey and unlocks this visit.
     *
     * @param  array<array-key, mixed>  $credential
     *
     * @throws InvalidPasskeyException
     */
    public function register(CaptureLink $link, array $credential): string
    {
        $options = $this->challenge('register', $link);
        $checked = $this->ceremony->attest($credential, $options);

        try {
            $passkey = CapturePasskey::query()->create([
                'organisation_id' => $link->organisation_id,
                'lease_id' => $link->lease_id,
                'phase' => $link->phase,
                'credential_id' => $checked['credential_id'],
                'credential' => $checked['credential'],
                'last_used_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw InvalidPasskeyException::make('A passkey is already set up for this link.');
        }

        $passkey->audit('capture_passkey_registered', null);

        return $this->unlock($link);
    }

    /**
     * Options for the phone to unlock with its passkey, or null when there is none.
     *
     * @return array<array-key, mixed>|null
     */
    public function verificationOptions(CaptureLink $link): ?array
    {
        $passkey = $this->passkey($link);

        if ($passkey === null) {
            return null;
        }

        $issued = $this->ceremony->requestOptions($passkey->credential_id);
        Cache::put('capture-passkey:verify:' . $link->id, $issued['options'], self::CHALLENGE_SECONDS);

        return $issued['browser'];
    }

    /**
     * Checks the phone's unlock and returns the unlock for this visit.
     *
     * @param  array<array-key, mixed>  $credential
     *
     * @throws InvalidPasskeyException
     */
    public function verify(CaptureLink $link, array $credential): string
    {
        $passkey = $this->passkey($link);

        if ($passkey === null) {
            throw InvalidPasskeyException::make('No passkey is set up for this link.');
        }

        $options = $this->challenge('verify', $link);
        $stored = $this->ceremony->assert($credential, $options, $passkey->credential_id, $passkey->credential);
        $passkey->forceFill(['credential' => $stored, 'last_used_at' => now()])->save();
        $passkey->audit('capture_unlocked', null);

        return $this->unlock($link);
    }

    private function passkey(CaptureLink $link): ?CapturePasskey
    {
        return CapturePasskey::query()->where('lease_id', $link->lease_id)->where('phase', $link->phase)->first();
    }

    /** The options issued for this ceremony, usable once. */
    private function challenge(string $purpose, CaptureLink $link): string
    {
        /** @var string|null $options */
        $options = Cache::pull('capture-passkey:' . $purpose . ':' . $link->id);

        if (!is_string($options)) {
            throw InvalidPasskeyException::make('That took too long. Try again.');
        }

        return $options;
    }

    private function unlock(CaptureLink $link): string
    {
        $unlock = Str::random(48);
        $seconds = (int) min(self::UNLOCK_SECONDS, max(1, now()->diffInSeconds($link->expires_at)));
        Cache::put(self::UNLOCK_CACHE . hash('sha256', $unlock), $link->id, $seconds);

        return $unlock;
    }
}
