<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Exceptions;

use RuntimeException;

final class OutboxFull extends RuntimeException
{
    public static function withQueued(int $queued): self
    {
        return new self("You have {$queued} emails queued. Clear the outbox before queuing more.");
    }
}
