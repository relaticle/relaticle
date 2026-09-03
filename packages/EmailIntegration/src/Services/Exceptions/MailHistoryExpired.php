<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Exceptions;

use RuntimeException;

final class MailHistoryExpired extends RuntimeException
{
    public static function forAccount(string $accountId): self
    {
        return new self("Mailbox history cursor expired for account {$accountId}; full resync required.");
    }
}
