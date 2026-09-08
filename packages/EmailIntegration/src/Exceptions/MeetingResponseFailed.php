<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Exceptions;

use RuntimeException;
use Throwable;

final class MeetingResponseFailed extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null,
        public readonly bool $missingMailboxCopy = false,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function fromProvider(Throwable $previous): self
    {
        return new self('The calendar provider rejected the RSVP update.', previous: $previous);
    }

    public static function missingMailboxCopy(): self
    {
        return new self(
            message: 'This mailbox does not have a provider event for the meeting.',
            missingMailboxCopy: true,
        );
    }
}
