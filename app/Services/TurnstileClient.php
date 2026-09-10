<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

final readonly class TurnstileClient
{
    private const string SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 5;

    private const int ATTEMPTS = 2;

    private const int RETRY_BACKOFF_MILLISECONDS = 100;

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function siteverify(string $token): TurnstileVerdict
    {
        $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->retry(self::ATTEMPTS, self::RETRY_BACKOFF_MILLISECONDS)
            ->asForm()
            ->acceptJson()
            ->post(self::SITEVERIFY_URL, [
                'secret' => (string) config('services.turnstile.secret'),
                'response' => $token,
            ]);

        return new TurnstileVerdict(
            success: $response->json('success') === true,
            errorCodes: $this->errorCodes($response->json('error-codes')),
        );
    }

    /**
     * @return list<string>
     */
    private function errorCodes(mixed $errorCodes): array
    {
        if (! is_array($errorCodes)) {
            return [];
        }

        return array_values(array_filter($errorCodes, is_string(...)));
    }
}
