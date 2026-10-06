<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;
use RuntimeException;

final readonly class RedirectController
{
    public const string WORKSPACE_SESSION_KEY = 'email_integration.oauth.workspace_id';

    public const string RETURN_URL_SESSION_KEY = 'email_integration.oauth.return_url';

    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        return match ($provider) {
            'gmail' => $this->startOAuth(
                $request,
                $user,
                $this->driver('gmail')
                    ->scopes($this->gmailScopes())
                    ->with(['access_type' => 'offline', 'prompt' => 'consent', 'login_hint' => $user->email]),
            ),

            'azure' => $this->startOAuth(
                $request,
                $user,
                $this->driver('azure')
                    ->setScopes($this->azureScopes())
                    ->with(['prompt' => 'consent', 'login_hint' => $user->email]),
            ),

            default => back(),
        };
    }

    private function startOAuth(Request $request, User $user, AbstractProvider $driver): RedirectResponse
    {
        $workspace = MailboxOAuthWorkspace::forUser($user, $request->query('workspace'));

        if (! $workspace instanceof Workspace) {
            return redirect('/')->with('error', 'Select a workspace before connecting an account.');
        }

        $request->session()->put(self::WORKSPACE_SESSION_KEY, $workspace->getKey());

        $returnUrl = $request->query('return');

        is_string($returnUrl)
            ? $request->session()->put(self::RETURN_URL_SESSION_KEY, $returnUrl)
            : $request->session()->forget(self::RETURN_URL_SESSION_KEY);

        return $driver->redirect();
    }

    /** @return array<int, string> */
    private function gmailScopes(): array
    {
        return [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
            'https://www.googleapis.com/auth/calendar.events',
        ];
    }

    /** @return array<int, string> */
    private function azureScopes(): array
    {
        return [
            'https://graph.microsoft.com/Mail.Read',
            'https://graph.microsoft.com/Mail.Send',
            'https://graph.microsoft.com/User.Read',
            'offline_access',
            'https://graph.microsoft.com/Calendars.ReadWrite',
        ];
    }

    private function driver(string $name): AbstractProvider
    {
        $driver = Socialite::driver($name);

        throw_unless($driver instanceof AbstractProvider, RuntimeException::class, "Socialite driver [{$name}] is not an OAuth2 provider.");

        return $driver;
    }
}
