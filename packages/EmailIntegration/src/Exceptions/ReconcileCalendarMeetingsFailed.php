<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Exceptions;

use RuntimeException;
use Throwable;

final class ReconcileCalendarMeetingsFailed extends RuntimeException
{
    public static function fromProvider(Throwable $previous): self
    {
        return new self('Calendar reconciliation failed: '.$previous->getMessage(), 0, $previous);
    }
}
