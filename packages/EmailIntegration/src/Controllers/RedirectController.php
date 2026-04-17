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
            'gmail' => $this->driver('google')
                ->stateless() // TODO: Remove stateless() once we can handle the state parameter properly
                ->scopes([
                    'https://www.googleapis.com/auth/gmail.readonly',
                    'https://www.googleapis.com/auth/gmail.send',
                ])
                ->with(['access_type' => 'offline', 'prompt' => 'consent'])
                ->redirect(),

            'azure' => $this->driver('azure')
                ->scopes([
                    'https://outlook.office.com/IMAP.AccessAsUser.All',
                    'https://outlook.office.com/Mail.Read',
                    'offline_access',
                ])
                ->redirect(),

            default => back(),
        };
    }

    private function driver(string $name): AbstractProvider
    {
        $driver = Socialite::driver($name);

        throw_unless($driver instanceof AbstractProvider, RuntimeException::class, "Socialite driver [{$name}] is not an OAuth2 provider.");

        return $driver;
    }
}
