<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Socialite;

final readonly class RedirectController
{
    public function __invoke(string $provider): RedirectResponse
    {
        return match ($provider) {
            'gmail' => Socialite::driver('google')
                ->stateless() // TODO: Remove stateless() once we can handle the state parameter properly
                ->scopes([
                    'https://www.googleapis.com/auth/gmail.readonly',
                    'https://www.googleapis.com/auth/gmail.send',
                ])
                ->with(['access_type' => 'offline', 'prompt' => 'consent'])
                ->redirect(),

            'azure' => Socialite::driver('azure')
                ->scopes([
                    'https://outlook.office.com/IMAP.AccessAsUser.All',
                    'https://outlook.office.com/Mail.Read',
                    'offline_access',
                ])
                ->redirect(),

            default => back(),
        };
    }
}
