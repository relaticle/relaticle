<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Auth;

use Relaticle\SystemAdmin\Models\SystemAdministratorPasskey;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;

/**
 * The vendor helper validates against the global customer origin list. Staff ceremonies
 * allow one origin, this panel's, so a customer-origin assertion never signs in here.
 */
final class StaffWebAuthn
{
    public static function attestationValidator(): AuthenticatorAttestationResponseValidator
    {
        return AuthenticatorAttestationResponseValidator::create(
            ceremonyStepManager: self::ceremonyStepManagerFactory()->creationCeremony(),
        );
    }

    public static function assertionValidator(): AuthenticatorAssertionResponseValidator
    {
        return AuthenticatorAssertionResponseValidator::create(
            ceremonyStepManager: self::ceremonyStepManagerFactory()->requestCeremony(),
        );
    }

    /** Scheme and port come from APP_URL, which the panel inherits; only the host moves. */
    public static function allowedOrigin(): string
    {
        $parsed = parse_url((string) config('app.url'));
        $scheme = $parsed['scheme'] ?? 'https';
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

        return $scheme.'://'.SystemAdministratorPasskey::relyingPartyId().$port;
    }

    private static function ceremonyStepManagerFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;

        $factory->setAllowedOrigins([self::allowedOrigin()]);

        return $factory;
    }
}
