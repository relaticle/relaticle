<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use App\Models\User;
use App\Models\Workspace;
use Filament\Notifications\Notification;
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
     * @var list<string>
     */
    private const array GMAIL_READ_SCOPES = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.modify',
        'https://mail.google.com/',
    ];

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
            return $this->redirectWithError($request, $user, 'That email provider is not supported.');
        }

        if ($request->query('error') === 'access_denied') {
            return $this->redirectAfterCancel($request, $user);
        }

        // Both 'gmail' (registered in EmailIntegrationServiceProvider from services.gmail)
        // and 'azure' resolve to their own OAuth clients + email-account redirects.
        $driver = Socialite::driver($provider);

        try {
            $socialUser = $driver->user();
        } catch (InvalidStateException) {
            Log::warning('OAuth callback state mismatch.', ['provider' => $provider, 'user_id' => $user->getKey()]);

            return $this->redirectWithError($request, $user, 'Your sign-in session expired. Please reconnect the account.', $this->boundWorkspace($request, $user));
        } catch (Throwable $e) {
            Log::error('OAuth callback failed.', ['provider' => $provider, 'user_id' => $user->getKey(), 'exception' => $e]);

            return $this->redirectWithError($request, $user, 'We could not connect that account. Please try again.', $this->boundWorkspace($request, $user));
        }

        throw_unless($socialUser instanceof TwoUser, RuntimeException::class, "Socialite driver [{$provider}] returned an unexpected user type.");

        $workspace = $this->consumeBoundWorkspace($request, $user);

        if (! $workspace instanceof Workspace) {
            return $this->redirectWithError($request, $user, 'Your sign-in session expired. Please reconnect the account.');
        }

        if (! $this->grantsMailRead($provider, $socialUser->approvedScopes)) {
            return $this->redirectWithError($request, $user, 'Relaticle needs permission to read your mail. Reconnect and allow every permission.', $workspace);
        }

        $this->connect($user, $workspace, $provider, $socialUser);

        Notification::make()
            ->title(__('filament/pages/email-accounts.notifications.connected.title'))
            ->body(__('filament/pages/email-accounts.notifications.connected.body'))
            ->success()
            ->send();

        $returnUrl = $request->session()->pull(RedirectController::RETURN_URL_SESSION_KEY);

        return redirect(is_string($returnUrl) ? $returnUrl : EmailAccountsPage::getUrl([
            'tenant' => $workspace->slug,
        ]));
    }

    private function connect(User $user, Workspace $workspace, string $provider, TwoUser $socialUser): void
    {
        /** @var array<int, string> $grantedScopes */
        $grantedScopes = $socialUser->approvedScopes;

        resolve(ConnectAccountAction::class)->execute(new ConnectAccountData(
            userId: $user->getKey(),
            workspaceId: $workspace->getKey(),
            provider: $provider,
            emailAddress: $socialUser->getEmail(),
            displayName: $socialUser->getName(),
            providerAccountId: $socialUser->getId(),
            accessToken: $socialUser->token,
            refreshToken: $socialUser->refreshToken,
            tokenExpiresAt: now()->addSeconds($socialUser->expiresIn),
            hasCalendar: $this->detectCalendarCapability($provider, $grantedScopes),
            hasSend: $this->detectSendCapability($provider, $grantedScopes),
        ));
    }

    private function boundWorkspace(Request $request, User $user): ?Workspace
    {
        return MailboxOAuthWorkspace::forUser($user, $request->session()->get(RedirectController::WORKSPACE_SESSION_KEY));
    }

    private function consumeBoundWorkspace(Request $request, User $user): ?Workspace
    {
        return MailboxOAuthWorkspace::forUser($user, $request->session()->pull(RedirectController::WORKSPACE_SESSION_KEY));
    }

    private function redirectAfterCancel(Request $request, User $user): RedirectResponse
    {
        Notification::make()
            ->title(__('filament/pages/email-accounts.notifications.cancelled.title'))
            ->warning()
            ->send();

        $returnUrl = $request->session()->pull(RedirectController::RETURN_URL_SESSION_KEY);
        $workspace = $this->consumeBoundWorkspace($request, $user) ?? $user->currentWorkspace;

        if (is_string($returnUrl)) {
            return redirect($returnUrl);
        }

        if ($workspace === null) {
            return redirect('/');
        }

        return redirect(EmailAccountsPage::getUrl(['tenant' => $workspace->slug]));
    }

    private function redirectWithError(Request $request, User $user, string $message, ?Workspace $workspace = null): RedirectResponse
    {
        $returnUrl = $request->session()->pull(RedirectController::RETURN_URL_SESSION_KEY);

        if (is_string($returnUrl)) {
            Notification::make()
                ->title($message)
                ->danger()
                ->send();

            return redirect($returnUrl);
        }

        $workspace ??= $user->currentWorkspace;

        if ($workspace === null) {
            return redirect('/')->with('error', $message);
        }

        return redirect(EmailAccountsPage::getUrl([
            'tenant' => $workspace->slug,
        ]))->with('error', $message);
    }

    /**
     * @param  array<int, string>  $approvedScopes
     */
    private function grantsMailRead(string $provider, array $approvedScopes): bool
    {
        // Only Google's consent screen lets a user untick a single permission.
        if ($provider !== 'gmail') {
            return true;
        }

        return $this->grantsAnyScope($approvedScopes, self::GMAIL_READ_SCOPES);
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
            fn (string $approved): bool => array_any(
                $wanted,
                fn (string $candidate): bool => $approved === $candidate
                    || str_ends_with($candidate, '/'.$approved),
            ),
        );
    }
}
