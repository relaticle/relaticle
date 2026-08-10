<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RyanChandler\LaravelCloudflareTurnstile\Contracts\ClientInterface;
use RyanChandler\LaravelCloudflareTurnstile\Responses\SiteverifyResponse;

/**
 * Replaces the package's siteverify client, which is wrong in two ways.
 *
 * It never reports success: every HTTP 200 becomes
 * `SiteverifyResponse::failure($response->json('error-codes'))`. Cloudflare's
 * own `success` flag is discarded, so the verdict is carried entirely by the
 * error codes — and a body without that key is a TypeError, since
 * `failure(array $errorCodes = [])` is not nullable.
 *
 * It also sets no timeout, so `Http::retry(3, 100)` inherits Laravel's 10s
 * connect / 30s request defaults: a black-holed connection holds a signup
 * submit for roughly a minute and a half before the visitor sees anything.
 */
final readonly class TurnstileClient implements ClientInterface
{
    private const string SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int REQUEST_TIMEOUT_SECONDS = 5;

    private const int ATTEMPTS = 2;

    private const int RETRY_BACKOFF_MILLISECONDS = 100;

    public function siteverify(string $response): SiteverifyResponse
    {
        $result = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->retry(self::ATTEMPTS, self::RETRY_BACKOFF_MILLISECONDS)
            ->asForm()
            ->acceptJson()
            ->post(self::SITEVERIFY_URL, [
                'secret' => (string) config('services.turnstile.secret'),
                'response' => $response,
            ]);

        if ($result->json('success') === true) {
            return SiteverifyResponse::success();
        }

        return SiteverifyResponse::failure($this->errorCodes($result->json('error-codes')));
    }

    public function dummy(): string
    {
        return self::RESPONSE_DUMMY_TOKEN;
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
