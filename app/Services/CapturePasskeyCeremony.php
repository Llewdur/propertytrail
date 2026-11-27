<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * The WebAuthn ceremonies for a capture passkey, on laravel/passkeys' validators but
 * without its PasskeyUser contract: the tenant has no account, the capture is the user.
 */
class CapturePasskeyCeremony
{
    /** @return array{options: string, browser: array<array-key, mixed>} */
    public function creationOptions(string $userHandle, string $label): array
    {
        $defaults = new GenerateRegistrationOptions();
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(name: Passkeys::relyingPartyName(), id: Passkeys::relyingPartyId()),
            user: PublicKeyCredentialUserEntity::create(name: $label, id: $userHandle, displayName: $label),
            challenge: random_bytes(32),
            pubKeyCredParams: $defaults->supportedAlgorithms(),
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                // The phone's own unlock, not a security key carried between phones.
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            timeout: Passkeys::timeout(),
        );

        return ['options' => WebAuthn::toJson($options), 'browser' => WebAuthn::toBrowserArray($options)];
    }

    /**
     * Checks a new credential against the options issued for it.
     *
     * @param  array<array-key, mixed>  $credential
     * @return array{credential_id: string, credential: array<string, mixed>}
     *
     * @throws InvalidPasskeyException
     */
    public function attest(array $credential, string $options): array
    {
        try {
            $response = $this->credential($credential)->response;

            if (!$response instanceof AuthenticatorAttestationResponse) {
                throw InvalidPasskeyException::make('This phone did not set up a passkey. Try again.');
            }

            $record = WebAuthn::attestationValidator()->check(
                authenticatorAttestationResponse: $response,
                publicKeyCredentialCreationOptions: WebAuthn::fromJson(
                    $options,
                    PublicKeyCredentialCreationOptions::class,
                ),
                host: Passkeys::relyingPartyId(),
            );
        } catch (InvalidPasskeyException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw InvalidPasskeyException::make('This phone did not set up a passkey. Try again.');
        }

        return [
            'credential_id' => Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId),
            'credential' => $this->stored($record),
        ];
    }

    /**
     * @return array{options: string, browser: array<array-key, mixed>}
     */
    public function requestOptions(string $credentialId): array
    {
        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: Passkeys::relyingPartyId(),
            allowCredentials: [
                PublicKeyCredentialDescriptor::create(
                    PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                    Base64UrlSafe::decodeNoPadding($credentialId),
                ),
            ],
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: Passkeys::timeout(),
        );

        return ['options' => WebAuthn::toJson($options), 'browser' => WebAuthn::toBrowserArray($options)];
    }

    /**
     * Checks an unlock against the stored passkey and returns the record to save,
     * whose signature counter catches a cloned authenticator next time.
     *
     * @param  array<array-key, mixed>  $credential
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     *
     * @throws InvalidPasskeyException
     */
    public function assert(array $credential, string $options, string $credentialId, array $stored): array
    {
        try {
            $parsed = $this->credential($credential);
            $response = $parsed->response;

            if (
                !$response instanceof AuthenticatorAssertionResponse
                || !hash_equals($credentialId, Base64UrlSafe::encodeUnpadded($parsed->rawId))
            ) {
                throw InvalidPasskeyException::make('That is not the passkey set up for this link.');
            }

            $record = WebAuthn::fromJson(json_encode($stored, JSON_THROW_ON_ERROR), CredentialRecord::class);
            $checked = WebAuthn::assertionValidator()->check(
                credentialRecord: $record,
                authenticatorAssertionResponse: $response,
                publicKeyCredentialRequestOptions: WebAuthn::fromJson(
                    $options,
                    PublicKeyCredentialRequestOptions::class,
                ),
                host: Passkeys::relyingPartyId(),
                userHandle: $record->userHandle,
            );
        } catch (InvalidPasskeyException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw InvalidPasskeyException::make('The phone could not be unlocked. Try again.');
        }

        return $this->stored($checked);
    }

    /** @param array<array-key, mixed> $credential */
    private function credential(array $credential): PublicKeyCredential
    {
        return WebAuthn::fromJson(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
    }

    /** @return array<string, mixed> */
    private function stored(CredentialRecord $record): array
    {
        /** @var array<string, mixed> */
        return json_decode(WebAuthn::toJson($record), true, flags: JSON_THROW_ON_ERROR);
    }
}
