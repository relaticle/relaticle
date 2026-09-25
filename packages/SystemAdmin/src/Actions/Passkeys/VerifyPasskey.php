<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions\Passkeys;

use Illuminate\Support\Facades\DB;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Relaticle\SystemAdmin\Auth\StaffWebAuthn;
use Relaticle\SystemAdmin\Models\SystemAdministratorPasskey;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

final readonly class VerifyPasskey
{
    /**
     * Only this table is searched, so a customer's credential is not a staff
     * credential no matter how valid its signature is.
     *
     * @throws InvalidPasskeyException
     */
    public function execute(
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $options,
    ): SystemAdministratorPasskey {
        if (! $credential->response instanceof AuthenticatorAssertionResponse) {
            throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
        }

        $response = $credential->response;
        $credentialId = Base64UrlSafe::encodeUnpadded($credential->rawId);

        return DB::transaction(function () use ($credentialId, $response, $options): SystemAdministratorPasskey {
            $passkey = SystemAdministratorPasskey::query()
                ->where('credential_id', $credentialId)
                ->lockForUpdate()
                ->first()
                ?? throw InvalidPasskeyException::make('Passkey not recognized. It may have been removed from your account.');

            $source = WebAuthn::fromJson(
                json_encode($passkey->credential, JSON_THROW_ON_ERROR),
                CredentialRecord::class,
            );

            // Forged assertion bytes surface as PHP warnings and bare
            // InvalidArgumentExceptions, not only the documented WebauthnException.
            try {
                $verified = StaffWebAuthn::assertionValidator()->check(
                    credentialRecord: $source,
                    authenticatorAssertionResponse: $response,
                    publicKeyCredentialRequestOptions: $options,
                    host: SystemAdministratorPasskey::relyingPartyId(),
                    userHandle: $source->userHandle,
                );
            } catch (Throwable) {
                throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
            }

            // Persisted after every use: the signature counter it carries is what
            // detects a cloned authenticator.
            $passkey->forceFill([
                'credential' => json_decode(WebAuthn::toJson($verified), true, flags: JSON_THROW_ON_ERROR),
                'last_used_at' => now(),
            ])->save();

            event(new PasskeyVerified($passkey->user, $passkey));

            return $passkey;
        });
    }
}
