<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialiteProvider;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use App\Support\Auth\LoginDestination;
use Illuminate\Http\Request;
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
    public function __invoke(Request $request, SocialiteProvider $provider, LoginDestination $destination): RedirectResponse
    {
        $user = $request->user();
        $referrer = $request->headers->get('referer');

        if (
            $user instanceof User
            && AuthenticationSession::pendingOperation() !== []
            && ! $request->session()->has('url.intended')
            && is_string($referrer)
            && $referrer !== route('password.confirm')
            && $destination->resolve($user, $referrer) === $referrer
        ) {
            $request->session()->put('url.intended', $referrer);
        }

        AuthenticationSession::stashOperationForProviderConfirm();

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider->value);

        $driver = $driver
            ->redirectUrl(route('auth.socialite.confirm.callback', ['provider' => $provider->value]))
            ->with(['prompt' => $provider === SocialiteProvider::GOOGLE ? 'select_account' : 'login']);

        return $driver->redirect();
    }
}
