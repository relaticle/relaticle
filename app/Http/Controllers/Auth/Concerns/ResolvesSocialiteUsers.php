<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Enums\SocialiteProvider;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Throwable;

trait ResolvesSocialiteUsers
{
    /**
     * @throws InvalidStateException
     * @throws Throwable
     */
    private function retrieveSocialUser(string $provider, ?string $redirectUrl = null): SocialiteUser
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider);

        if ($redirectUrl !== null) {
            $driver->redirectUrl($redirectUrl);
        }

        $user = $driver->user();

        if ($driver instanceof MicrosoftProvider && $user instanceof AbstractUser) {
            $user->setRaw([...$user->getRaw(), 'tid' => $driver->getClaims()?->tid]);
        }

        return $user;
    }

    private function parseProviderError(string $exceptionMessage, SocialiteProvider $provider): string
    {
        $errorPatterns = [
            'invalid_request' => __('auth.provider.invalid_request'),
            'access_denied' => __('auth.provider.access_denied'),
        ];

        foreach ($errorPatterns as $pattern => $message) {
            if (str_contains($exceptionMessage, $pattern)) {
                return $message;
            }
        }

        return __('auth.provider.failed', ['provider' => $provider->label()]);
    }
}
