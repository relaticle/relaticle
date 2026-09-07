<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewSocialUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Contracts\User\CreatesNewSocialUsers;
use App\Http\Controllers\Auth\MfaChallengeController;
use App\Http\Controllers\Auth\PasskeySessionController;
use App\Http\Controllers\Auth\PasswordSessionController;
use App\Listeners\MarkTwoFactorEnrollmentCompleteListener;
use App\Support\Auth\AuthenticationSession;
use Filament\Facades\Filament;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;

final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreatesNewSocialUsers::class, CreateNewSocialUser::class);
        $this->app->bind(AuthenticatedSessionController::class, PasswordSessionController::class);
        $this->app->bind(TwoFactorAuthenticatedSessionController::class, MfaChallengeController::class);

        // The vendor controller authenticates before resolving its response, so a
        // response-only override cannot enforce MFA; swap the whole controller.
        $this->app->bind(PasskeyLoginController::class, PasskeySessionController::class);
    }

    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Jetstream registers the Blade-based `auth.verify-email` prompt view for
        // Fortify's `/email/verify` route, but this app renders all auth UI through
        // Filament and never publishes that view. Send unverified users hitting the
        // Fortify verification-notice route to Filament's real prompt instead.
        Fortify::verifyEmailView(fn (): RedirectResponse => to_route(
            Filament::getPanel('app')->getEmailVerificationPromptRouteName(),
        ));

        // Confirming a TOTP code during enrollment is itself MFA proof for the
        // session that just performed it, not only for logins completed afterward.
        Event::listen(TwoFactorAuthenticationConfirmed::class, MarkTwoFactorEnrollmentCompleteListener::class);

        RateLimiter::for('login', function (Request $request): Limit {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            $pending = AuthenticationSession::pending();
            $key = $pending === [] ? $request->session()->getId() : $pending['user_id'];

            return Limit::perMinute(5)->by($key);
        });

        /**
         * Every passkey ceremony costs two requests (options, then assertion), so this
         * allows far more than a person can perform while still capping scripted
         * assertion verification. Keyed per user where the route is authenticated, so
         * shared egress IPs only aggregate on the guest login endpoint.
         */
        RateLimiter::for('passkeys', fn (Request $request): Limit => Limit::perMinute(30)
            ->by($request->user()?->getAuthIdentifier() ?? (string) $request->ip()));
    }
}
