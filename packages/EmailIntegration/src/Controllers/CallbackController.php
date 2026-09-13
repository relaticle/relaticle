<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use App\Models\Team;
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
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;
use RuntimeException;
use Throwable;

final readonly class CallbackController
{
    private const array SUPPORTED_PROVIDERS = ['gmail', 'azure'];

    /**
     * Google Calendar grants that mean Relaticle may sync meetings. Granular
     * consent can return any one of these instead of the full requested set.
     *
     * @var list<string>
     */
    private const array GMAIL_CALENDAR_SCOPES = [
        'https://www.googleapis.com/auth/calendar.readonly',
        'https://www.googleapis.com/auth/calendar.events',
        'https://www.googleapis.com/auth/calendar.events.readonly',
        'https://www.googleapis.com/auth/calendar',
    ];

    /**
     * @var list<string>
     */
    private const array AZURE_CALENDAR_SCOPES = [
        'https://graph.microsoft.com/Calendars.Read',
        'https://graph.microsoft.com/Calendars.ReadWrite',
    ];

    /**
     * Grants that mean Relaticle may send mail from this mailbox.
     *
     * @var list<string>
     */
    private const array GMAIL_SEND_SCOPES = [
        'https://www.googleapis.com/auth/gmail.send',
        'https://mail.google.com/',
    ];

    /**
     * @var list<string>
     */
    private const array AZURE_SEND_SCOPES = [
        'https://graph.microsoft.com/Mail.Send',
    ];

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if (! in_array($provider, self::SUPPORTED_PROVIDERS, true)) {
            return $this->redirectWithError($user, 'That email provider is not supported.');
        }

        // Both 'gmail' (registered in EmailIntegrationServiceProvider from services.gmail)
        // and 'azure' resolve to their own OAuth clients + email-account redirects.
        $driver = Socialite::driver($provider);

        try {
            $socialUser = $driver->user();
        } catch (InvalidStateException) {
            Log::warning('OAuth callback state mismatch.', ['provider' => $provider, 'user_id' => $user->getKey()]);

            return $this->redirectWithError($user, 'Your sign-in session expired. Please reconnect the account.', $this->boundWorkspace($request, $user));
        } catch (Throwable $e) {
            Log::error('OAuth callback failed.', ['provider' => $provider, 'user_id' => $user->getKey(), 'exception' => $e]);

            return $this->redirectWithError($user, 'We could not connect that account. Please try again.', $this->boundWorkspace($request, $user));
        }

        throw_unless($socialUser instanceof TwoUser, RuntimeException::class, "Socialite driver [{$provider}] returned an unexpected user type.");

        $team = $this->consumeBoundWorkspace($request, $user);

        if (! $team instanceof Team) {
            return $this->redirectWithError($user, 'Your sign-in session expired. Please reconnect the account.');
        }

        /** @var array<int, string> $grantedScopes */
        $grantedScopes = $socialUser->approvedScopes;
        $hasCalendar = $this->detectCalendarCapability($provider, $grantedScopes);
        $hasSend = $this->detectSendCapability($provider, $grantedScopes);

        resolve(ConnectAccountAction::class)->execute(new ConnectAccountData(
            userId: $user->getKey(),
            teamId: $team->getKey(),
            provider: $provider,
            emailAddress: $socialUser->getEmail(),
            displayName: $socialUser->getName(),
            providerAccountId: $socialUser->getId(),
            accessToken: $socialUser->token,
            refreshToken: $socialUser->refreshToken,
            tokenExpiresAt: now()->addSeconds($socialUser->expiresIn),
            hasCalendar: $hasCalendar,
            hasSend: $hasSend,
        ));

        return redirect(EmailAccountsPage::getUrl([
            'tenant' => $team->slug,
        ]))->with('success', 'Account connected successfully.');
    }

    private function boundWorkspace(Request $request, User $user): ?Team
    {
        return MailboxOAuthWorkspace::forUser($user, $request->session()->get(RedirectController::WORKSPACE_SESSION_KEY));
    }

    private function consumeBoundWorkspace(Request $request, User $user): ?Team
    {
        return MailboxOAuthWorkspace::forUser($user, $request->session()->pull(RedirectController::WORKSPACE_SESSION_KEY));
    }

    private function redirectWithError(User $user, string $message, ?Team $team = null): RedirectResponse
    {
        $team ??= $user->currentTeam;

        if ($team === null) {
            return redirect('/')->with('error', $message);
        }

        return redirect(EmailAccountsPage::getUrl([
            'tenant' => $team->slug,
        ]))->with('error', $message);
    }

    /**
     * @param  array<int, string>  $approvedScopes
     */
    private function detectCalendarCapability(string $provider, array $approvedScopes): bool
    {
        return match ($provider) {
            'gmail' => $this->grantsAnyScope($approvedScopes, self::GMAIL_CALENDAR_SCOPES),
            'azure' => $this->grantsAnyScope($approvedScopes, self::AZURE_CALENDAR_SCOPES),
            default => false,
        };
    }

    /**
     * @param  array<int, string>  $approvedScopes
     */
    private function detectSendCapability(string $provider, array $approvedScopes): bool
    {
        return match ($provider) {
            'gmail' => $this->grantsAnyScope($approvedScopes, self::GMAIL_SEND_SCOPES),
            'azure' => $this->grantsAnyScope($approvedScopes, self::AZURE_SEND_SCOPES),
            default => false,
        };
    }

    /**
     * @param  array<int, string>  $approvedScopes
     * @param  list<string>  $wanted
     */
    private function grantsAnyScope(array $approvedScopes, array $wanted): bool
    {
        return array_any(
            $approvedScopes,
            fn (string $scope): bool => in_array($scope, $wanted, true),
        );
    }
}
