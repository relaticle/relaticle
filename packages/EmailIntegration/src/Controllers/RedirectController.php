<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use RuntimeException;

final readonly class RedirectController
{
    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        $capability = $request->query('capability');
        $includeCalendar = $capability === 'calendar';

        return match ($provider) {
            'gmail' => $this->driver('google')
                ->scopes($this->gmailScopes($includeCalendar))
                ->with(['access_type' => 'offline', 'prompt' => 'consent'])
                ->redirect(),

            'azure' => $this->driver('azure')
                ->scopes($this->azureScopes($includeCalendar))
                ->with(['prompt' => 'consent'])
                ->redirect(),

            default => back(),
        };
    }

    /** @return array<int, string> */
    private function gmailScopes(bool $includeCalendar): array
    {
        $scopes = [
            'https://www.googleapis.com/auth/gmail.readonly',
            'https://www.googleapis.com/auth/gmail.send',
        ];

        if ($includeCalendar) {
            $scopes[] = 'https://www.googleapis.com/auth/calendar.readonly';
        }

        return $scopes;
    }

    /** @return array<int, string> */
    private function azureScopes(bool $includeCalendar): array
    {
        $scopes = [
            'https://graph.microsoft.com/Mail.Read',
            'https://graph.microsoft.com/Mail.Send',
            'https://graph.microsoft.com/User.Read',
            'offline_access',
        ];

        if ($includeCalendar) {
            $scopes[] = 'https://graph.microsoft.com/Calendars.Read';
        }

        return $scopes;
    }

    private function driver(string $name): AbstractProvider
    {
        $driver = Socialite::driver($name);

        throw_unless($driver instanceof AbstractProvider, RuntimeException::class, "Socialite driver [{$name}] is not an OAuth2 provider.");

        return $driver;
    }
}
