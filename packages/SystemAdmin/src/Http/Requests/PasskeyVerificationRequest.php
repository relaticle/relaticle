<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Requests;

use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest as BaseRequest;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * One browser can hold a customer session and a staff session at once, so the two
 * ceremonies cannot share the session key the base request uses.
 */
final class PasskeyVerificationRequest extends BaseRequest
{
    public const string SESSION_KEY = 'passkey.sysadmin.verification_options';

    /**
     * @throws ValidationException
     */
    public function verificationOptions(): PublicKeyCredentialRequestOptions
    {
        $serialized = $this->session()->pull(self::SESSION_KEY);

        if (! is_string($serialized) || $serialized === '') {
            throw ValidationException::withMessages([
                'credential' => __('Passkey verification session expired. Please try again.'),
            ]);
        }

        return WebAuthn::fromJson($serialized, PublicKeyCredentialRequestOptions::class);
    }
}
