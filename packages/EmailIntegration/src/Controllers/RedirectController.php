<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use RuntimeException;

final readonly class RedirectController
{
    public function __invoke(string $provider): RedirectResponse
    {
        return match ($provider) {
            'gmail' => $this->driver('gmail')
                ->scopes($this->gmailScopes())
                ->with(['access_type' => 'offline', 'prompt' => 'consent'])
                ->redirect(),

            'azure' => $this->driver('azure')
                ->setScopes($this->azureScopes())
                ->with(['prompt' => 'consent'])
                ->redirect(),

            default => back(),
        };
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
