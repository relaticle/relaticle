<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    #[Override]
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     * The allowlist comes from HORIZON_ADMIN_EMAILS and defaults to empty, so
     * a deployment that has not opted anyone in denies everyone. The listed
     * user must also have a verified email and a second factor (confirmed
     * two-factor or a passkey), so registering the address is not enough.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function (User $user): bool {
            /** @var array<int, string> $adminEmails */
            $adminEmails = config('relaticle.horizon.admin_emails', []);

            if ($adminEmails === [] || ! $user->hasVerifiedEmail()) {
                return false;
            }

            if (! $user->hasEnabledTwoFactorAuthentication() && ! $user->hasPasskey()) {
                return false;
            }

            return in_array(
                mb_strtolower($user->email),
                array_map(mb_strtolower(...), $adminEmails),
                strict: true,
            );
        });
    }
}
