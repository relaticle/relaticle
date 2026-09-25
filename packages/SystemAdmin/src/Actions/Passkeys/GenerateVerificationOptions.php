<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions\Passkeys;

use Laravel\Passkeys\Passkeys;
use Relaticle\SystemAdmin\Models\SystemAdministratorPasskey;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialRequestOptions;

final readonly class GenerateVerificationOptions
{
    /**
     * No credential list: staff sign in before naming an account, so the browser
     * has to resolve the administrator from a discoverable credential.
     */
    public function execute(): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: SystemAdministratorPasskey::relyingPartyId(),
            allowCredentials: [],
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: Passkeys::timeout(),
        );
    }
}
