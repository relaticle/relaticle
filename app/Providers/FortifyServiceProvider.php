<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewSocialUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Contracts\User\CreatesNewSocialUsers;
use App\Http\Responses\PasskeyLoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;

final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreatesNewSocialUsers::class, CreateNewSocialUser::class);
        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
    }

    public function boot(): void
    {
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));
    }
}
