<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Exceptions;

use RuntimeException;

final class CalendarSyncTokenExpired extends RuntimeException
{
    public static function forAccount(string $accountId): self
    {
        return new self("Calendar sync token expired for account {$accountId}; full resync required.");
    }
}
