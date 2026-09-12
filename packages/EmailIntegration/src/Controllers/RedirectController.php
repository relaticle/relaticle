<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use RuntimeException;

final readonly class RedirectController
{
    public const string WORKSPACE_SESSION_KEY = 'email_integration.oauth.team_id';

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
                    ->with(['access_type' => 'offline', 'prompt' => 'consent']),
            ),

            'azure' => $this->startOAuth(
                $request,
                $user,
                $this->driver('azure')
                    ->setScopes($this->azureScopes())
                    ->with(['prompt' => 'consent']),
            ),

            default => back(),
        };
    }

    private function startOAuth(Request $request, User $user, AbstractProvider $driver): RedirectResponse
    {
        $team = $user->currentTeam;

        if (! $team instanceof Team) {
            return redirect('/')->with('error', 'Select a team before connecting an account.');
        }

        $request->session()->put(self::WORKSPACE_SESSION_KEY, $team->getKey());

        return $driver->redirect();
    }

    /** @return array<int, string> */
    private function gmailScopes(): array
    {
        return [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
            'https://www.googleapis.com/auth/calendar.events',
            'https://www.googleapis.com/auth/calendar.readonly',
        ];
    }

    /** @return array<int, string> */
    private function azureScopes(): array
    {
        return [
            'https://graph.microsoft.com/Mail.Read',
            'https://graph.microsoft.com/Mail.ReadWrite',
            'https://graph.microsoft.com/Mail.Send',
            'https://graph.microsoft.com/User.Read',
            'offline_access',
            'https://graph.microsoft.com/Calendars.ReadWrite',
            'https://graph.microsoft.com/Calendars.Read',
        ];
    }

    private function driver(string $name): AbstractProvider
    {
        $driver = Socialite::driver($name);

        throw_unless($driver instanceof AbstractProvider, RuntimeException::class, "Socialite driver [{$name}] is not an OAuth2 provider.");

        return $driver;
    }
}
