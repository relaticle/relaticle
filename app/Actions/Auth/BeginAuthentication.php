<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AuthMethod;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;

final readonly class BeginAuthentication
{
    public function __construct(private CompleteAuthentication $completeAuthentication) {}

    public function execute(User $user, AuthMethod $method, ?string $credentialId, bool $remember): string
    {
        AuthenticationSession::begin($user, $method, $credentialId, $remember);

        if ($method !== AuthMethod::PASSKEY && $user->hasEnabledTwoFactorAuthentication()) {
            event(new TwoFactorAuthenticationChallenged($user));

            return route('two-factor.login');
        }

        return $this->completeAuthentication->execute();
    }
}
