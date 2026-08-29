<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Streaming\Events\Error;
use RuntimeException;
use Throwable;

final readonly class ProviderStreamError
{
    private const array RETRYABLE_TYPES = [
        'overloaded_error',
        'rate_limit_error',
        'api_error',
        'timeout_error',
    ];

    public static function toException(Error $event): Throwable
    {
        if (self::isRetryable($event)) {
            return new ProviderOverloadedException(
                "Provider stream error [{$event->type}]: {$event->message}",
            );
        }

        return new RuntimeException(
            "Provider stream error [{$event->type}]: {$event->message}",
        );
    }

    /**
     * Whether a provider-reported stream error is worth another attempt.
     *
     * Also answers for laravel/ai's own StreamErrorException, which carries the
     * provider's Error event on ->error (null when the stream simply ended
     * without completing the step, not retryable on its own).
     */
    public static function isRetryable(?Error $event): bool
    {
        return $event instanceof Error && in_array($event->type, self::RETRYABLE_TYPES, true);
    }
}
