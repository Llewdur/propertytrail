<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\CapturePasskeyCeremony;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;

/**
 * Stands in for the phone's authenticator: a credential is accepted when its
 * response echoes the challenge the server issued, and refused otherwise.
 */
final class FakeCapturePasskeyCeremony extends CapturePasskeyCeremony
{
    public int $issued = 0;

    /** @return array{options: string, browser: array<array-key, mixed>} */
    public function creationOptions(string $userHandle, string $label): array
    {
        return $this->issue();
    }

    /** @return array{credential_id: string, credential: array<string, mixed>} */
    public function attest(array $credential, string $options): array
    {
        $this->check($credential, $options);

        return ['credential_id' => (string) $credential['id'], 'credential' => ['counter' => 0]];
    }

    /** @return array{options: string, browser: array<array-key, mixed>} */
    public function requestOptions(string $credentialId): array
    {
        return $this->issue();
    }

    /** @return array<string, mixed> */
    public function assert(array $credential, string $options, string $credentialId, array $stored): array
    {
        $this->check($credential, $options);

        if ($credential['id'] !== $credentialId) {
            throw InvalidPasskeyException::make('That is not the passkey set up for this link.');
        }

        return ['counter' => (int) ($stored['counter'] ?? 0) + 1];
    }

    /** @return array{options: string, browser: array<array-key, mixed>} */
    private function issue(): array
    {
        $challenge = 'challenge-' . ++$this->issued;

        return ['options' => $challenge, 'browser' => ['challenge' => $challenge]];
    }

    /** @param array<array-key, mixed> $credential */
    private function check(array $credential, string $options): void
    {
        $response = $credential['response'] ?? null;

        if (!is_array($response) || ($response['challenge'] ?? null) !== $options) {
            throw InvalidPasskeyException::make('The phone could not be unlocked. Try again.');
        }
    }
}
