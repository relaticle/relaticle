<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class CallbackController
{
    /**
     * @throws \Throwable
     */
    public function __invoke(string $provider): RedirectResponse
    {
        $socialUser = Socialite::driver($this->resolveDriver($provider))
            ->stateless() // TODO: Remove stateless() once we can handle the state parameter properly
            ->user();

        DB::transaction(function () use ($provider, $socialUser): void {
            $user = auth()->user();

            ConnectedAccount::updateOrCreate(
                [
                    'user_id' => $user->getKey(),
                    'provider' => $provider,
                    'email_address' => $socialUser->getEmail(),
                    'team_id' => $user->currentTeam->getKey(),
                ],
                [
                    'display_name' => $socialUser->getName(),
                    'provider_account_id' => $socialUser->getId(),
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => now()->addSeconds($socialUser->expiresIn ?? 3600),
                    'status' => 'active',
                    'last_error' => null,
                ]
            );
        });

        return redirect()->route('filament.app.pages.email-accounts')
            ->with('success', 'Email account connected successfully.');
    }

    private function resolveDriver(string $provider): string
    {
        return match ($provider) {
            'gmail' => 'google',
            'azure' => 'azure',
            default => $provider,
        };
    }
}
