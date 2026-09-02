<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Contracts\User\CreatesNewSocialUsers;
use App\Enums\SocialiteProvider;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\EmailAddress;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

final readonly class CallbackController
{
    public function __invoke(
        Request $request,
        SocialiteProvider $provider,
        CreatesNewSocialUsers $creator
    ): RedirectResponse {
        if (! $request->has('code')) {
            return $this->handleError('Authorization was cancelled or failed. Please try again.');
        }

        try {
            $socialUser = $this->retrieveSocialUser($provider->value);
            $user = $this->resolveUser($provider->value, $socialUser, $creator);

            if ($user->wasRecentlyCreated) {
                $this->flagSignupForAnalytics();
            }

            return $this->loginAndRedirect($user);
        } catch (InvalidStateException) {
            return $this->handleError('Authentication state mismatch. Please try again.');
        } catch (ValidationException $e) {
            return $this->handleError($e->validator->errors()->first());
        } catch (Throwable $e) {
            report($e);

            return $this->handleError($this->parseProviderError($e->getMessage(), $provider->value));
        }
    }

    /**
     * @throws InvalidStateException
     * @throws Throwable
     */
    private function retrieveSocialUser(string $provider): SocialiteUser
    {
        return Socialite::driver($provider)->user();
    }

    private function resolveUser(
        string $provider,
        SocialiteUser $socialUser,
        CreatesNewSocialUsers $creator
    ): User {
        return DB::transaction(function () use ($provider, $socialUser, $creator): User {
            $existingAccount = UserSocialAccount::query()
                ->with('user')
                ->where('provider_name', $provider)
                ->where('provider_id', $socialUser->getId())
                ->first();

            if ($existingAccount?->user) {
                return $existingAccount->user;
            }

            $email = $socialUser->getEmail();
            $user = $email
                ? User::query()->where('email', EmailAddress::canonicalize($email))->first()
                : null;

            if (! $user) {
                $user = $this->createUser($socialUser, $creator, $provider);
            }

            $this->linkSocialAccount($user, $provider, $socialUser->getId());

            return $user;
        });
    }

    private function createUser(
        SocialiteUser $socialUser,
        CreatesNewSocialUsers $creator,
        string $provider
    ): User {
        return $creator->create([
            'name' => $this->extractName($socialUser),
            'email' => $this->extractEmail($socialUser, $provider),
            'terms' => 'on',
        ]);
    }

    /**
     * The signup conversion event, matching what the registration form flags.
     *
     * Social sign-ups reached the panel without this, so the event counted the
     * email form alone and every OAuth provider was missing from it. The count
     * itself was never the point: the users table has that, exactly. What only
     * the client-side event carries is the referrer that brought the person
     * here, and a whole signup channel was arriving unattributed.
     *
     * Flagged out here rather than inside resolveUser(): written in there it
     * would outlive a rolled back transaction, because the session saves at the
     * end of the request either way, and report a signup that never happened.
     */
    private function flagSignupForAnalytics(): void
    {
        session()->put('fathom.track_signup', true);
    }

    private function linkSocialAccount(User $user, string $provider, string|int $providerId): void
    {
        $user->socialAccounts()->updateOrCreate(
            [
                'provider_name' => $provider,
                'provider_id' => (string) $providerId,
            ]
        );
    }

    private function extractName(SocialiteUser $socialUser): string
    {
        return $socialUser->getName()
            ?? $socialUser->getNickname()
            ?? 'Unknown User';
    }

    private function extractEmail(SocialiteUser $socialUser, string $provider): string
    {
        return EmailAddress::canonicalize(
            $socialUser->getEmail() ?? sprintf('%s_%s@noemail.app', $provider, $socialUser->getId()),
        );
    }

    private function parseProviderError(string $exceptionMessage, string $provider): string
    {
        $errorPatterns = [
            'invalid_request' => 'Invalid authentication request. Please try again.',
            'access_denied' => 'Access was denied. Please authorize the application to continue.',
        ];

        foreach ($errorPatterns as $pattern => $message) {
            if (str_contains($exceptionMessage, $pattern)) {
                return $message;
            }
        }

        return sprintf('Failed to authenticate with %s.', ucfirst($provider));
    }

    private function handleError(string $message): RedirectResponse
    {
        Notification::make()
            ->title('Authentication Failed')
            ->body($message)
            ->danger()
            ->persistent()
            ->send();

        return to_route('login')
            ->withErrors(['login' => $message])
            ->with('error', $message);
    }

    private function loginAndRedirect(User $user): RedirectResponse
    {
        Auth::login($user, remember: true);

        return redirect()->intended(url()->getAppUrl());
    }
}
