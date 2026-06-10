<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as TwoUser;
use Relaticle\EmailIntegration\Actions\ConnectAccountAction;
use Relaticle\EmailIntegration\Data\ConnectAccountData;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use RuntimeException;
use Throwable;

final readonly class CallbackController
{
    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $driver = Socialite::driver($this->resolveDriver($provider));

        try {
            $socialUser = $driver->user();
        } catch (InvalidStateException) {
            Log::warning('OAuth callback state mismatch.', ['provider' => $provider, 'user_id' => $user->getKey()]);

            return $this->redirectWithError($user, 'Your sign-in session expired. Please reconnect the account.');
        } catch (Throwable $e) {
            Log::error('OAuth callback failed.', ['provider' => $provider, 'user_id' => $user->getKey(), 'exception' => $e]);

            return $this->redirectWithError($user, 'We could not connect that account. Please try again.');
        }

        throw_unless($socialUser instanceof TwoUser, RuntimeException::class, "Socialite driver [{$provider}] returned an unexpected user type.");

        /** @var array<int, string> $grantedScopes */
        $grantedScopes = $socialUser->approvedScopes;
        $hasCalendar = $this->detectCalendarCapability($provider, $grantedScopes);

        $account = resolve(ConnectAccountAction::class)->execute(new ConnectAccountData(
            userId: $user->getKey(),
            teamId: $user->currentTeam->getKey(),
            provider: $provider,
            emailAddress: $socialUser->getEmail(),
            displayName: $socialUser->getName(),
            providerAccountId: $socialUser->getId(),
            accessToken: $socialUser->token,
            refreshToken: $socialUser->refreshToken,
            tokenExpiresAt: now()->addSeconds($socialUser->expiresIn),
            hasCalendar: $hasCalendar,
        ));

        if ($hasCalendar) {
            dispatch(new InitialCalendarSyncJob($account));
        }

        return redirect(EmailAccountsPage::getUrl([
            'tenant' => $user->currentTeam->slug,
        ]))->with('success', 'Account connected successfully.');
    }

    private function resolveDriver(string $provider): string
    {
        return match ($provider) {
            'gmail' => 'google',
            'azure' => 'azure',
            default => $provider,
        };
    }

    private function redirectWithError(User $user, string $message): RedirectResponse
    {
        return redirect(EmailAccountsPage::getUrl([
            'tenant' => $user->currentTeam->slug,
        ]))->with('error', $message);
    }

    /**
     * @param  array<int, string>  $approvedScopes
     */
    private function detectCalendarCapability(string $provider, array $approvedScopes): bool
    {
        return match ($provider) {
            'gmail' => in_array('https://www.googleapis.com/auth/calendar.readonly', $approvedScopes, true),
            'azure' => in_array('https://graph.microsoft.com/Calendars.Read', $approvedScopes, true),
            default => false,
        };
    }
}
