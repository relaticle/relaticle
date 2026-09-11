<?php

declare(strict_types=1);

namespace App\Rules;

use App\Features\SignupChallenge;
use App\Services\TurnstileClient;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * Fails closed: anything that is not an explicit siteverify success is a
 * failure, including an unreachable Cloudflare. Passing on error would hand an
 * attacker a bypass they can trigger on demand.
 */
final readonly class TurnstileChallenge implements ValidationRule
{
    public static function isEnabled(): bool
    {
        return Feature::active(SignupChallenge::class)
            && filled(config('services.turnstile.key'))
            && filled(config('services.turnstile.secret'));
    }

    /**
     * @return array<int, ValidationRule|string>
     */
    public static function rules(): array
    {
        if (! self::isEnabled()) {
            return [];
        }

        return ['required', new self];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $verdict = resolve(TurnstileClient::class)->siteverify(is_string($value) ? $value : '');
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('Cloudflare turnstile siteverify is unreachable; the challenge failed closed.', [
                'attribute' => $attribute,
                'exception' => $exception->getMessage(),
            ]);

            $fail(__('auth.turnstile.unavailable'));

            return;
        }

        if ($verdict->success) {
            return;
        }

        Log::info('Cloudflare turnstile rejected a signup token.', ['error_codes' => $verdict->errorCodes]);

        $fail(__('auth.turnstile.failed'));
    }
}
