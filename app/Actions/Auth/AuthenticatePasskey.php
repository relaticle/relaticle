<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AuthMethod;
use App\Models\User;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkeys;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

final readonly class AuthenticatePasskey
{
    public function __construct(
        private VerifyPasskey $verifyPasskey,
        private BeginAuthentication $beginAuthentication,
    ) {}

    public function execute(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, bool $remember): string
    {
        try {
            $passkey = ($this->verifyPasskey)($credential, $options);
        } catch (WebauthnException) {
            throw InvalidPasskeyException::make('Unable to verify passkey. Please try again.');
        }

        if (! Passkeys::allowsLogin(request(), $passkey)) {
            throw InvalidPasskeyException::make('Unable to sign in with this account.');
        }

        $user = $passkey->user;

        if (! $user instanceof User) {
            throw InvalidPasskeyException::make('Unable to sign in with this account.');
        }

        return $this->beginAuthentication->execute($user, AuthMethod::PASSKEY, (string) $passkey->getKey(), $remember);
    }
}
