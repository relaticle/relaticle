<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Per-mailbox cooldown for Gmail/Graph user-rate limits. One 429 on a connected
 * account must park every other fetch for that mailbox until Retry-After, or
 * workers keep calling the API and Google slides the lock forward.
 */
final readonly class ProviderRateLimit
{
    private const int MIN_SECONDS = 10;

    private const int MAX_SECONDS = 1800;

    private const int FALLBACK_SECONDS = 60;

    public static function remainingSeconds(string $accountId): ?int
    {
        $until = Cache::get(self::key($accountId));

        if (! is_numeric($until)) {
            return null;
        }

        $remaining = (int) $until - now()->getTimestamp();

        return $remaining > 0 ? $remaining : null;
    }

    public static function trip(string $accountId, int $seconds): void
    {
        $seconds = self::clamp($seconds);

        Cache::put(self::key($accountId), now()->addSeconds($seconds)->getTimestamp(), $seconds);
    }

    public static function retryAfterSeconds(Throwable $exception): ?int
    {
        $current = $exception;

        while ($current instanceof Throwable) {
            $seconds = self::secondsFor($current);

            if ($seconds !== null) {
                return $seconds;
            }

            $current = $current->getPrevious();
        }

        return null;
    }

    private static function secondsFor(Throwable $exception): ?int
    {
        if (! self::isRateLimit($exception)) {
            return null;
        }

        $fromHeader = self::headerSeconds($exception);

        if ($fromHeader !== null) {
            return self::clamp($fromHeader);
        }

        $message = $exception->getMessage();

        if (preg_match('/Retry after (\d{4}-\d{2}-\d{2}T[\d:.]+Z)/i', $message, $matches) === 1) {
            $until = Date::parse($matches[1]);
            $seconds = $until->getTimestamp() - now()->getTimestamp();

            return self::clamp($seconds > 0 ? $seconds : self::FALLBACK_SECONDS);
        }

        return self::clamp(self::FALLBACK_SECONDS);
    }

    private static function isRateLimit(Throwable $exception): bool
    {
        $status = self::httpStatus($exception);

        if ($status === 429) {
            return true;
        }

        if ($status !== 403) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'ratelimitexceeded')
            || str_contains($message, 'rate limit exceeded');
    }

    private static function headerSeconds(Throwable $exception): ?int
    {
        $value = self::retryAfterHeader($exception);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $until = Date::parse($value);
        $seconds = $until->getTimestamp() - now()->getTimestamp();

        return $seconds > 0 ? $seconds : self::FALLBACK_SECONDS;
    }

    private static function retryAfterHeader(Throwable $exception): ?string
    {
        if ($exception instanceof RequestException) {
            $header = $exception->response->header('Retry-After');

            return $header !== '' ? $header : null;
        }

        if (! method_exists($exception, 'getResponse')) {
            return null;
        }

        $response = $exception->getResponse();

        if (! $response instanceof ResponseInterface) {
            return null;
        }

        $header = $response->getHeaderLine('Retry-After');

        return $header !== '' ? $header : null;
    }

    private static function httpStatus(Throwable $exception): ?int
    {
        if ($exception instanceof RequestException) {
            return $exception->response->status();
        }

        if (method_exists($exception, 'getResponse')) {
            $response = $exception->getResponse();

            if ($response instanceof ResponseInterface) {
                return $response->getStatusCode();
            }
        }

        $code = $exception->getCode();

        return is_int($code) && $code >= 100 && $code < 600 ? $code : null;
    }

    private static function clamp(int $seconds): int
    {
        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds));
    }

    private static function key(string $accountId): string
    {
        return "email-integration:provider-rate-limit:{$accountId}";
    }
}
