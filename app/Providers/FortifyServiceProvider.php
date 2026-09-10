<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewSocialUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Passkeys\DeletePasskey;
use App\Actions\Passkeys\VerifyPasskey;
use App\Contracts\User\CreatesNewSocialUsers;
use App\Http\Controllers\Auth\IdentityConfirmationController;
use App\Http\Controllers\Auth\MfaChallengeController;
use App\Http\Controllers\Auth\PasskeyConfirmationController;
use App\Http\Controllers\Auth\PasskeySessionController;
use App\Http\Controllers\Auth\PasswordSessionController;
use App\Listeners\MarkTwoFactorEnrollmentCompleteListener;
use App\Support\Auth\AuthenticationSession;
use Filament\Facades\Filament;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;
use Laravel\Passkeys\Actions\DeletePasskey as VendorDeletePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey as VendorVerifyPasskey;
use Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController as VendorPasskeyConfirmationController;
use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;

final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreatesNewSocialUsers::class, CreateNewSocialUser::class);
        $this->app->bind(AuthenticatedSessionController::class, PasswordSessionController::class);
        $this->app->bind(TwoFactorAuthenticatedSessionController::class, MfaChallengeController::class);
        $this->app->bind(VendorDeletePasskey::class, DeletePasskey::class);
        $this->app->bind(VendorVerifyPasskey::class, VerifyPasskey::class);

        // The vendor controller authenticates before resolving its response, so a
        // response-only override cannot enforce MFA; swap the whole controller.
        $this->app->bind(PasskeyLoginController::class, PasskeySessionController::class);

        // Both vendor controllers mark the session confirmed directly on success;
        // ours routes the same proof through ConfirmIdentity so a scoped operation
        // grant and enrolled MFA apply identically to every confirmation path.
        $this->app->bind(ConfirmablePasswordController::class, IdentityConfirmationController::class);
        $this->app->bind(VendorPasskeyConfirmationController::class, PasskeyConfirmationController::class);
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

        $this->requireOperationGrantsOnVendorRoutes();
    }

    /**
     * Fortify and the standalone Laravel\Passkeys package both register routes
     * named passkey.store/passkey.destroy; whichever provider boots last wins
     * the route collection, so neither package's own config middleware key is
     * reliable here. Appending directly to the final, already-registered route
     * objects inside a booted() callback works regardless of that ordering.
     */
    private function requireOperationGrantsOnVendorRoutes(): void
    {
        $this->app->booted(function (): void {
            $grantParametersByRouteName = [
                'passkey.store' => 'add_passkey',
                'passkey.destroy' => 'delete_passkey',
                'two-factor.enable' => 'manage_mfa,enable',
                'two-factor.disable' => 'manage_mfa,disable',
                // Reading the secret or the recovery codes hands over a durable
                // second factor, and regenerating them locks the owner out, so
                // each needs the same grant as enabling or disabling.
                'two-factor.confirm' => 'manage_mfa,confirm',
                'two-factor.qr-code' => 'manage_mfa,show_qr_code',
                'two-factor.secret-key' => 'manage_mfa,show_secret_key',
                'two-factor.recovery-codes' => 'manage_mfa,show_recovery_codes',
                'two-factor.regenerate-recovery-codes' => 'manage_mfa,regenerate_recovery_codes',
            ];

            // Route::name() is fluent, applied after the route is first added to
            // the collection, so the name-index cache (getByName()) is not yet
            // populated for a route registered inside another provider's own
            // boot(). Matching each route's own getName() sidesteps that cache.
            foreach ($this->app->make(Router::class)->getRoutes()->getRoutes() as $route) {
                $operation = $grantParametersByRouteName[$route->getName()] ?? null;

                if ($operation !== null) {
                    $route->middleware("require-operation:{$operation}");
                }
            }
        });
    }
}
