<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialiteProvider;
use App\Support\Auth\AuthenticationSession;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirect for a fresh, authenticated provider confirmation. Google documents
 * account selection (`select_account`) as its supported re-authentication
 * signal; account selection does not force credential re-entry. Microsoft
 * documents `login` as the parameter that does force it, which this sensitive
 * operation needs.
 */
final readonly class IdentityConfirmationRedirectController
{
    public function __invoke(SocialiteProvider $provider): RedirectResponse
    {
        // The operation grant in flight (if any) must survive the OAuth round
        // trip identified by its own id, not by "whatever is in the session
        // slot when the user returns": a second modal opened in another tab
        // while the provider redirect is in progress overwrites that slot.
        AuthenticationSession::stashOperationForProviderConfirm();

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider->value);

        $driver = $driver
            ->redirectUrl(route('auth.socialite.confirm.callback', ['provider' => $provider->value]))
            ->with(['prompt' => $provider === SocialiteProvider::GOOGLE ? 'select_account' : 'login']);

        return $driver->redirect();
    }
}
