<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Exceptions;

use RuntimeException;
use Throwable;

final class MeetingResponseFailed extends RuntimeException
{
    public static function fromProvider(Throwable $previous): self
    {
        return new self('The calendar provider rejected the RSVP update.', previous: $previous);
    }
}
