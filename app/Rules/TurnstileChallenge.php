<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use RyanChandler\LaravelCloudflareTurnstile\Facades\Turnstile;

/**
 * The Cloudflare turnstile challenge, made safe for a public page.
 *
 * This owns the pass/fail verdict rather than delegating it to the package's
 * `Rules\Turnstile`, which reports a failure only while looping the response's
 * error codes — so `success: false` with no codes is silently a pass. Anything
 * that is not explicitly successful fails here, codes or not.
 *
 * Siteverify outages fail closed rather than open: waving the challenge through
 * on error would hand an attacker a bypass they can trigger on demand. The
 * visitor gets a "try again" message instead, and the warning log makes the
 * outage visible.
 */
final readonly class TurnstileChallenge implements ValidationRule
{
    /**
     * A site key without a secret cannot be verified server-side, so a
     * half-configured install would present a challenge that can never pass.
     * Self-hosted instances with neither are not the abuse target.
     */
    public static function isConfigured(): bool
    {
        return filled(config('services.turnstile.key'))
            && filled(config('services.turnstile.secret'));
    }

    /**
     * @return array<int, ValidationRule|string>
     */
    public static function rules(): array
    {
        if (! self::isConfigured()) {
            return [];
        }

        return ['required', new self];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $response = Turnstile::siteverify(is_string($value) ? $value : '');
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Cloudflare turnstile siteverify is unreachable; the challenge failed closed.', [
                'attribute' => $attribute,
                'exception' => $exception->getMessage(),
            ]);

            $fail(__('auth.turnstile.unavailable'));

            return;
        }

        if ($response->success) {
            return;
        }

        if ($response->errorCodes === []) {
            $fail(__('auth.turnstile.failed'));

            return;
        }

        foreach ($response->errorCodes as $errorCode) {
            $fail(match ($errorCode) {
                'missing-input-secret' => __('cloudflare-turnstile::errors.missing-input-secret'),
                'invalid-input-secret' => __('cloudflare-turnstile::errors.invalid-input-secret'),
                'missing-input-response' => __('cloudflare-turnstile::errors.missing-input-response'),
                'invalid-input-response' => __('cloudflare-turnstile::errors.invalid-input-response'),
                'bad-request' => __('cloudflare-turnstile::errors.bad-request'),
                'timeout-or-duplicate' => __('cloudflare-turnstile::errors.timeout-or-duplicate'),
                'internal-error' => __('cloudflare-turnstile::errors.internal-error'),
                default => __('cloudflare-turnstile::errors.unexpected'),
            });
        }
    }
}
