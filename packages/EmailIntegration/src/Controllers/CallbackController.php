<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as TwoUser;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use RuntimeException;

final readonly class CallbackController
{
    public function __invoke(Request $request, string $provider): RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $driver = Socialite::driver($this->resolveDriver($provider));

        $socialUser = $driver->user();

        throw_unless($socialUser instanceof TwoUser, RuntimeException::class, "Socialite driver [{$provider}] returned an unexpected user type.");

        $grantedScopes = $socialUser->approvedScopes;
        $hasCalendar = in_array('https://www.googleapis.com/auth/calendar.readonly', $grantedScopes, true);

        $account = DB::transaction(fn (): ConnectedAccount => ConnectedAccount::query()->updateOrCreate(
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
                'token_expires_at' => now()->addSeconds($socialUser->expiresIn),
                'status' => 'active',
                'last_error' => null,
                'auto_create_companies' => true,
                'contact_creation_mode' => ContactCreationMode::All,
                'capabilities' => [
                    'email' => true,
                    'calendar' => $hasCalendar,
                ],
            ]
        ));

        if ($hasCalendar) {
            dispatch(new InitialCalendarSyncJob($account));
        }

        return redirect(EmailAccountsPage::getUrl([
            'tenant' => $user->currentTeam->slug,
        ]))->with('success', 'Account connected successfully.');
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
