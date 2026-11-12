<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

final readonly class RedirectController
{
    public function __invoke(SocialiteProvider $provider): RedirectResponse
    {
        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider->value);
        $callbackUrl = $provider->callbackUrl();

        if ($callbackUrl !== null) {
            $driver->redirectUrl($callbackUrl);
        }

        return $driver->redirect();
    }
}
