<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LinkSocialAccount;
use App\Enums\SocialiteProvider;
use App\Http\Controllers\Auth\Concerns\ResolvesSocialiteUsers;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Complete a brand-new provider association. Only the exact grant id stashed
 * before the redirect may be spent here, never whatever operation happens to
 * occupy the single session slot on return; a freshly completed OAuth
 * transaction is what proves the provider identity being bound to it.
 */
final readonly class LinkSocialAccountCallbackController
{
    use ResolvesSocialiteUsers;

    public function __construct(private LinkSocialAccount $linkSocialAccount) {}

    public function __invoke(Request $request, SocialiteProvider $provider): RedirectResponse
    {
        $user = $request->user();

        // Consumed unconditionally, before any early return, so a stash from
        // this redirect never leaks into a later, unrelated link attempt.
        $stashedGrantId = AuthenticationSession::consumeStashedProviderOperationGrantId();

        if (! $user instanceof User) {
            return to_route('password.confirm');
        }

        if (! $request->has('code')) {
            return $this->handleLinkError(__('auth.confirm.cancelled'));
        }

        try {
            $socialUser = $this->retrieveSocialUser($provider->value, route('auth.socialite.link.callback', ['provider' => $provider->value]));
        } catch (InvalidStateException) {
            return $this->handleLinkError(__('auth.confirm.state_mismatch'));
        } catch (Throwable $e) {
            report($e);

            return $this->handleLinkError($this->parseProviderError($e->getMessage(), $provider->value));
        }

        $pending = AuthenticationSession::pendingOperation();
        $grantValid = $stashedGrantId !== null
            && $pending !== []
            && $pending['id'] === $stashedGrantId
            && $pending['user_id'] === (string) $user->getAuthIdentifier();

        if (! $grantValid) {
            return $this->handleLinkError(__('auth.confirm.required'));
        }

        try {
            AuthenticationSession::bindOperationTarget($user, 'link_provider', $stashedGrantId, $provider->value.':'.$socialUser->getId());
            $this->linkSocialAccount->execute($user, $provider, (string) $socialUser->getId());
        } catch (ValidationException $e) {
            return $this->handleLinkError($e->validator->errors()->first());
        }

        return redirect()->intended(Fortify::redirects('password-confirmation'));
    }

    private function handleLinkError(string $message): RedirectResponse
    {
        Notification::make()
            ->title(__('auth.confirm.failed_title'))
            ->body($message)
            ->danger()
            ->persistent()
            ->send();

        return to_route('password.confirm')->with('error', $message);
    }
}
