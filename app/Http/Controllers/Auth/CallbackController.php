<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\BeginAuthentication;
use App\Contracts\User\CreatesNewSocialUsers;
use App\Enums\AuthMethod;
use App\Enums\SocialiteProvider;
use App\Http\Controllers\Auth\Concerns\ResolvesSocialiteUsers;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Support\Auth\AuthenticationSession;
use App\Support\EmailAddress;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

final readonly class CallbackController
{
    use ResolvesSocialiteUsers;

    public function __construct(private BeginAuthentication $beginAuthentication) {}

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
            $account = $this->resolveUser($provider->value, $socialUser, $creator);

            if (! $account instanceof UserSocialAccount) {
                return $this->handleError(__('auth.link.account_exists'));
            }

            $user = $account->user;

            if (! $user instanceof User) {
                return $this->handleError('Authentication state mismatch. Please try again.');
            }

            if ($user->wasRecentlyCreated) {
                $this->flagSignupForAnalytics();
            }

            return $this->beginAndRedirect($user, $provider, $account);
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
     * A matching email is never proof of ownership on its own, so a guest
     * callback with no existing (provider, provider_id) association must
     * never create or update one. It returns null and leaves a short-lived
     * link suggestion for the caller to surface instead, requiring the person
     * to authenticate normally before an explicit link flow can run.
     */
    private function resolveUser(
        string $provider,
        SocialiteUser $socialUser,
        CreatesNewSocialUsers $creator
    ): ?UserSocialAccount {
        return DB::transaction(function () use ($provider, $socialUser, $creator): ?UserSocialAccount {
            $existingAccount = UserSocialAccount::query()
                ->with('user')
                ->where('provider_name', $provider)
                ->where('provider_id', $socialUser->getId())
                ->first();

            if ($existingAccount?->user) {
                return $existingAccount;
            }

            $email = $socialUser->getEmail();
            $canonicalEmail = $email !== null ? EmailAddress::canonicalize($email) : null;
            $matchedUser = $canonicalEmail !== null
                ? User::query()->where('email', $canonicalEmail)->first()
                : null;

            if ($matchedUser instanceof User) {
                AuthenticationSession::suggestLink($provider, (string) $socialUser->getId(), $canonicalEmail);

                return null;
            }

            $user = $this->createUser($socialUser, $creator, $provider);

            return $this->linkSocialAccount($user, $provider, $socialUser->getId());
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

    private function linkSocialAccount(User $user, string $provider, string|int $providerId): UserSocialAccount
    {
        $account = $user->socialAccounts()->updateOrCreate(
            [
                'provider_name' => $provider,
                'provider_id' => (string) $providerId,
            ]
        );
        $account->setRelation('user', $user);

        return $account;
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

    private function beginAndRedirect(User $user, SocialiteProvider $provider, UserSocialAccount $account): RedirectResponse
    {
        $next = $this->beginAuthentication->execute(
            $user,
            AuthMethod::from($provider->value),
            (string) $account->getKey(),
            remember: true,
        );

        return redirect()->to($next);
    }
}
