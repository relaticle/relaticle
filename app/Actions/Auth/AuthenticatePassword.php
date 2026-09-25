<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AuthMethod;
use App\Models\User;
use App\Support\EmailAddress;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class AuthenticatePassword
{
    public function __construct(private BeginAuthentication $beginAuthentication) {}

    public function execute(string $email, #[SensitiveParameter] string $password, bool $remember): string
    {
        $provider = $this->provider();
        $credentials = [
            'email' => EmailAddress::canonicalize($email),
            'password' => $password,
        ];
        $guardName = (string) config('fortify.guard', 'web');

        $user = resolve(Timebox::class)->call(function (Timebox $timebox) use ($provider, $credentials, $guardName, $remember): User {
            event(new Attempting($guardName, $credentials, $remember));

            $user = $provider->retrieveByCredentials($credentials);

            if (
                ! $user instanceof User
                || ! $provider->validateCredentials($user, $credentials)
                || ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())
            ) {
                event(new Failed($guardName, $user, $credentials));

                throw ValidationException::withMessages([
                    'email' => [__('auth.failed')],
                ]);
            }

            $timebox->returnEarly();

            return $user;
        }, (int) config('auth.timebox_duration', 200_000));

        if (config('hashing.rehash_on_login', true)) {
            $provider->rehashPasswordIfRequired($user, $credentials);
        }

        return $this->beginAuthentication->execute($user, AuthMethod::PASSWORD, null, $remember);
    }

    private function provider(): UserProvider
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard((string) config('fortify.guard', 'web'));

        return $guard->getProvider();
    }
}
