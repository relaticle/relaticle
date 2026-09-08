<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Exceptions;

use RuntimeException;
use Throwable;

final class CalendarPushChannelFailed extends RuntimeException
{
    public static function fromProvider(Throwable $previous): self
    {
        return new self('Failed to renew the calendar push channel.', 0, $previous);
    }

    public static function unableToCreate(): self
    {
        return new self('Failed to create the calendar push channel.');
    }
}
