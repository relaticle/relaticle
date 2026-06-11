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
        if (in_array($event->type, self::RETRYABLE_TYPES, true)) {
            return new ProviderOverloadedException(
                "Provider stream error [{$event->type}]: {$event->message}",
            );
        }

        return new RuntimeException(
            "Provider stream error [{$event->type}]: {$event->message}",
        );
    }
}
