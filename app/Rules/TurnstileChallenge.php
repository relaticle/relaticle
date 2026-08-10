<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile;

/**
 * The Cloudflare turnstile rule, made safe for a public page.
 *
 * The vendor client issues the siteverify call through Http::retry() with
 * throwing left on, so its own fail-open branch is unreachable and any
 * Cloudflare hiccup escapes as an unhandled exception — a 500 on the
 * registration page.
 *
 * We fail closed rather than open: waving the challenge through on error would
 * hand an attacker a bypass they can trigger on demand. The visitor gets a
 * "try again" message instead, and the warning log makes the outage visible.
 */
final readonly class TurnstileChallenge implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            (new Turnstile)->validate($attribute, $value, $fail);
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Cloudflare turnstile siteverify is unreachable; registration challenge failed closed.', [
                'exception' => $exception->getMessage(),
            ]);

            $fail(__('auth.turnstile.unavailable'));
        }
    }
}
