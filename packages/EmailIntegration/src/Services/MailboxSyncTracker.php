<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Cache;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class MailboxSyncTracker
{
    private const int TTL_MINUTES = 30;

    public static function markCalendarStarted(ConnectedAccount $account): void
    {
        Cache::put(self::calendarKey($account), true, now()->addMinutes(self::TTL_MINUTES));
    }

    public static function markCalendarFinished(ConnectedAccount $account): void
    {
        Cache::forget(self::calendarKey($account));
    }

    public static function isCalendarSyncing(ConnectedAccount $account): bool
    {
        return Cache::has(self::calendarKey($account));
    }

    public static function markEmailStarted(ConnectedAccount $account): void
    {
        Cache::put(self::emailKey($account), true, now()->addMinutes(self::TTL_MINUTES));
    }

    public static function markEmailFinished(ConnectedAccount $account): void
    {
        Cache::forget(self::emailKey($account));
    }

    public static function isEmailSyncing(ConnectedAccount $account): bool
    {
        return Cache::has(self::emailKey($account));
    }

    private static function calendarKey(ConnectedAccount $account): string
    {
        return 'mailbox-sync:calendar:'.$account->getKey();
    }

    private static function emailKey(ConnectedAccount $account): string
    {
        return 'mailbox-sync:email:'.$account->getKey();
    }
}
