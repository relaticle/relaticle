<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Exceptions;

use RuntimeException;

final class AgentOutboxFull extends RuntimeException
{
    public static function atLimit(int $limit): self
    {
        return new self("The user already has {$limit} assistant emails waiting to send. Wait until they send or the user cancels them.");
    }
}
