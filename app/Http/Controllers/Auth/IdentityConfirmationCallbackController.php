<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConfirmIdentity;
use App\Enums\AuthMethod;
use App\Enums\SocialiteProvider;
use App\Http\Controllers\Auth\Concerns\ResolvesSocialiteUsers;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Confirm identity for a sensitive operation using a provider already linked
 * to the current account. A newly completed OAuth transaction proves current
 * access to that provider identity; a stored Relaticle session does not, so
 * this never trusts anything but the subject Socialite just returned.
 */
final readonly class IdentityConfirmationCallbackController
{
    use ResolvesSocialiteUsers;

    public function __construct(private ConfirmIdentity $confirmIdentity) {}

    public function __invoke(Request $request, SocialiteProvider $provider): RedirectResponse
    {
        $user = $request->user();

        // Consumed unconditionally, before any early return, so a stash from
        // this redirect never leaks into a later, unrelated confirm attempt.
        $stashedGrantId = AuthenticationSession::consumeStashedProviderOperationGrantId();

        if (! $user instanceof User) {
            return to_route('password.confirm');
        }

        if (! $request->has('code')) {
            return $this->handleConfirmError(__('auth.confirm.cancelled'));
        }

        try {
            $socialUser = $this->retrieveSocialUser($provider->value, route('auth.socialite.confirm.callback', ['provider' => $provider->value]));
        } catch (InvalidStateException) {
            return $this->handleConfirmError(__('auth.confirm.state_mismatch'));
        } catch (Throwable $e) {
            report($e);

            return $this->handleConfirmError($this->parseProviderError($e->getMessage(), $provider->value));
        }

        $account = $user->socialAccounts()->where('provider_name', $provider->value)->first();

        if (! $account instanceof UserSocialAccount || $account->provider_id !== (string) $socialUser->getId()) {
            return $this->handleConfirmError(__('auth.confirm.not_linked'));
        }

        // Trust the current session slot only if it is still the exact grant
        // that was active when this redirect began. A second modal opened
        // elsewhere during the OAuth round trip overwrites the single slot;
        // that unrelated grant must never be treated as proven by this return.
        $pending = AuthenticationSession::pendingOperation();
        $attemptId = $stashedGrantId !== null
            && $pending !== []
            && $pending['id'] === $stashedGrantId
            && $pending['user_id'] === (string) $user->getAuthIdentifier()
                ? $pending['id']
                : null;

        try {
            $next = $this->confirmIdentity->execute($user, $attemptId, AuthMethod::from($provider->value), ['provider_verified' => true]);
        } catch (ValidationException $e) {
            return $this->handleConfirmError($e->validator->errors()->first());
        }

        return $next !== '' ? redirect()->to($next) : redirect()->intended(Fortify::redirects('password-confirmation'));
    }

    private function handleConfirmError(string $message): RedirectResponse
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
