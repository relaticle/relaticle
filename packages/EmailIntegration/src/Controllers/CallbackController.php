<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class CallbackController
{
    /**
     * @throws \Throwable
     */
    public function __invoke(string $provider): RedirectResponse
    {
        /**
         * @var User $user
         */
        $user = auth()->user();

        $socialUser = Socialite::driver($this->resolveDriver($provider))
            ->stateless() // TODO: Remove stateless() once we can handle the state parameter properly
            ->user();

        DB::transaction(function () use ($provider, $socialUser, $user): void {

            ConnectedAccount::query()->updateOrCreate(
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
                    'auto_create_companies' => true,
                    'contact_creation_mode' => ContactCreationMode::All,
                ]
            );
        });

        return redirect(EmailAccountsPage::getUrl([
            'tenant' => $user->currentTeam->slug,
        ]))->with('success', 'Email account connected successfully.');
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
