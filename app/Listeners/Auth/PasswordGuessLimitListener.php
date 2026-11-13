<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Support\EmailAddress;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class PasswordGuessLimitListener
{
    private const int PER_WINDOW = 10;

    private const int WINDOW_SECONDS = 900;

    public function handle(Attempting|Failed|Login|PasswordReset $event): void
    {
        match (true) {
            $event instanceof Attempting => $this->assertAllowed($event),
            $event instanceof Failed => $this->recordFailure($event),
            $event instanceof Login => $this->clear($event->guard, $event->user),
            $event instanceof PasswordReset => $this->clear((string) config('fortify.guard', 'web'), $event->user),
        };
    }

    private function assertAllowed(Attempting $event): void
    {
        $email = $this->email($event->credentials);

        if ($email === '') {
            return;
        }

        $field = $event->guard === 'sysadmin' ? 'data.email' : 'email';
        $windowKey = $this->windowKey($event->guard, $email);

        if (RateLimiter::tooManyAttempts($windowKey, self::PER_WINDOW)) {
            throw ValidationException::withMessages([
                $field => __('auth.throttle', ['seconds' => RateLimiter::availableIn($windowKey)]),
            ]);
        }
    }

    private function recordFailure(Failed $event): void
    {
        $email = $this->email($event->credentials);

        if ($email === '') {
            return;
        }

        RateLimiter::hit($this->windowKey($event->guard, $email), self::WINDOW_SECONDS);
    }

    private function clear(string $guard, ?Authenticatable $user): void
    {
        $email = EmailAddress::canonicalize((string) data_get($user, 'email'));

        RateLimiter::clear($this->windowKey($guard, $email));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function email(array $credentials): string
    {
        return EmailAddress::canonicalize((string) ($credentials['email'] ?? ''));
    }

    private function windowKey(string $guard, string $email): string
    {
        return "password-guess-window:{$guard}:{$email}";
    }
}
