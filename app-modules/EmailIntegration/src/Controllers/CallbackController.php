<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Socialite;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class CallbackController
{
    /**
     * @throws \Throwable
     */
    public function __invoke(string $provider): RedirectResponse
    {
        $socialUser = Socialite::driver($this->resolveDriver($provider))
            ->stateless() // TODO: Remove when we have proper state handling
            ->user();

        DB::transaction(function () use ($provider, $socialUser): void {
            $user = auth()->user();

            ConnectedAccount::updateOrCreate(
                [
                    'user_id' => '01kkepbxd8b8v1qp474adrsrvm',
                    'provider' => $provider,
                    'email_address' => $socialUser->getEmail(),
                    'team_id' => '01kkepbxdb4w2j2bemeea6aaa5',
                ],
                [
                    'display_name' => $socialUser->getName(),
                    'provider_account_id' => $socialUser->getId(), // Google sub / MS oid — prevents duplicates
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => now()->addSeconds($socialUser->expiresIn ?? 3600),
                    'status' => 'active',
                    'last_error' => null,
                ]
            );
        });

        return redirect('/')
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
