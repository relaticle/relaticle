<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions\Passkeys;

use Cose\Algorithms;
use Laravel\Passkeys\Passkeys;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Models\SystemAdministratorPasskey;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

final readonly class GenerateRegistrationOptions
{
    public function execute(SystemAdministrator $administrator): PublicKeyCredentialCreationOptions
    {
        $relyingPartyId = SystemAdministratorPasskey::relyingPartyId();

        return PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(name: $relyingPartyId, id: $relyingPartyId),
            user: PublicKeyCredentialUserEntity::create(
                name: $administrator->getPasskeyUsername(),
                id: $administrator->getPasskeyUserHandle(),
                displayName: $administrator->getPasskeyDisplayName(),
            ),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, Algorithms::COSE_ALGORITHM_RS256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $this->registeredCredentials($administrator),
            timeout: Passkeys::timeout(),
        );
    }

    /**
     * @return array<PublicKeyCredentialDescriptor>
     */
    private function registeredCredentials(SystemAdministrator $administrator): array
    {
        return $administrator->passkeys()
            ->get()
            ->map(fn (SystemAdministratorPasskey $passkey): PublicKeyCredentialDescriptor => PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                Base64UrlSafe::decodeNoPadding($passkey->credential_id),
            ))
            ->all();
    }
}
