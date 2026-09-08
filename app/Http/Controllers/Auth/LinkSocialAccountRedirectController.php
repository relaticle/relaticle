<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialiteProvider;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirect to establish a brand-new provider association. The route's own
 * password.confirm gate is the first proof (current access to the Relaticle
 * account); completing the OAuth round trip below is the second (current
 * access to the provider identity being linked). Reuses the confirm redirect's
 * explicit provider-interaction policy so a stale, already-selected browser
 * session cannot stand in for a freshly completed transaction.
 */
final readonly class LinkSocialAccountRedirectController
{
    public function __invoke(Request $request, SocialiteProvider $provider): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        AuthenticationSession::startOperation($user, 'link_provider', null);
        AuthenticationSession::stashOperationForProviderConfirm();

        /** @var AbstractProvider $driver */
        $driver = Socialite::driver($provider->value);

        // Prompt choice duplicated from IdentityConfirmationRedirectController;
        // see its docblock for why select_account/login differ per provider.
        $driver = $driver
            ->redirectUrl(route('auth.socialite.link.callback', ['provider' => $provider->value]))
            ->with(['prompt' => $provider === SocialiteProvider::GOOGLE ? 'select_account' : 'login']);

        return $driver->redirect();
    }
}
