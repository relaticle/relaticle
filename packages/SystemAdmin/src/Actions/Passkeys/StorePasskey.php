<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions\Passkeys;

use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Relaticle\SystemAdmin\Auth\StaffWebAuthn;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Models\SystemAdministratorPasskey;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

final readonly class StorePasskey
{
    /**
     * @throws InvalidPasskeyException
     */
    public function execute(
        SystemAdministrator $administrator,
        string $name,
        PublicKeyCredential $credential,
        PublicKeyCredentialCreationOptions $options,
    ): SystemAdministratorPasskey {
        if (! $credential->response instanceof AuthenticatorAttestationResponse) {
            throw InvalidPasskeyException::make('Unable to register passkey. Please try again.');
        }

        $source = StaffWebAuthn::attestationValidator()->check(
            authenticatorAttestationResponse: $credential->response,
            publicKeyCredentialCreationOptions: $options,
            host: SystemAdministratorPasskey::relyingPartyId(),
        );

        $credentialId = Base64UrlSafe::encodeUnpadded($source->publicKeyCredentialId);

        if (SystemAdministratorPasskey::query()->where('credential_id', $credentialId)->exists()) {
            throw InvalidPasskeyException::make('Unable to register this passkey.');
        }

        $passkey = $administrator->passkeys()->create([
            'name' => $name,
            'credential_id' => $credentialId,
            'credential' => json_decode(WebAuthn::toJson($source), true, flags: JSON_THROW_ON_ERROR),
        ]);

        event(new PasskeyRegistered($administrator, $passkey));

        return $passkey;
    }
}
