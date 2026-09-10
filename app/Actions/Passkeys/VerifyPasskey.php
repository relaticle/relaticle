<?php

declare(strict_types=1);

namespace App\Actions\Passkeys;

use Laravel\Passkeys\Actions\VerifyPasskey as VendorVerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkey;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialRequestOptions;

final class VerifyPasskey extends VendorVerifyPasskey
{
    protected function validate(
        AuthenticatorAssertionResponse $response,
        Passkey $passkey,
        PublicKeyCredentialRequestOptions $options
    ): CredentialRecord {
        // Forged assertion bytes surface as PHP warnings and bare
        // InvalidArgumentExceptions, not only the documented WebauthnException.
        try {
            return parent::validate($response, $passkey, $options);
        } catch (Throwable) {
            throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
        }
    }
}
