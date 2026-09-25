<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest as BaseRequest;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * One browser can hold a customer session and a staff session at once, so the two
 * ceremonies cannot share the session key the base request uses.
 */
final class PasskeyRegistrationRequest extends BaseRequest
{
    public const string SESSION_KEY = 'passkey.sysadmin.registration_options';

    /**
     * Registration is two requests, so the code the profile page asks for has to
     * leave something behind for the request that actually stores the credential.
     */
    public const string GRANT_KEY = 'passkey.sysadmin.registration_grant';

    /**
     * Checked here rather than in the controller so an ungranted request is refused
     * before its payload is validated.
     */
    public function authorize(): bool
    {
        return self::isGranted($this);
    }

    public static function isGranted(Request $request): bool
    {
        $grantedUntil = $request->session()->get(self::GRANT_KEY);

        return $grantedUntil instanceof CarbonImmutable && $grantedUntil->isFuture();
    }

    /**
     * @throws ValidationException
     */
    public function registrationOptions(): PublicKeyCredentialCreationOptions
    {
        $serialized = $this->session()->pull(self::SESSION_KEY);

        if (! is_string($serialized) || $serialized === '') {
            throw ValidationException::withMessages([
                'credential' => __('Passkey registration session expired. Please try again.'),
            ]);
        }

        return WebAuthn::fromJson($serialized, PublicKeyCredentialCreationOptions::class);
    }
}
