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
        Cache::put(self::calendarProcessedKey($account), 0, now()->addMinutes(self::TTL_MINUTES));
        Cache::forget(self::calendarTotalKey($account));
        Cache::increment(self::calendarGenerationKey($account));
    }

    public static function currentCalendarSyncGeneration(ConnectedAccount $account): int
    {
        return (int) Cache::get(self::calendarGenerationKey($account), 0);
    }

    public static function isCalendarSyncGenerationCurrent(ConnectedAccount $account, int $generation): bool
    {
        return $generation === self::currentCalendarSyncGeneration($account);
    }

    public static function hasCalendarRunTotal(ConnectedAccount $account): bool
    {
        $total = Cache::get(self::calendarTotalKey($account));

        return is_int($total) && $total > 0;
    }

    public static function markCalendarFinished(ConnectedAccount $account): void
    {
        Cache::forget(self::calendarKey($account));
        Cache::forget(self::calendarProcessedKey($account));
        Cache::forget(self::calendarTotalKey($account));
    }

    public static function setCalendarRunTotal(ConnectedAccount $account, int $total): void
    {
        Cache::put(self::calendarTotalKey($account), $total, now()->addMinutes(self::TTL_MINUTES));
    }

    public static function addCalendarRunTotal(ConnectedAccount $account, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $key = self::calendarTotalKey($account);

        if (! Cache::has($key)) {
            Cache::put($key, $count, now()->addMinutes(self::TTL_MINUTES));

            return;
        }

        Cache::increment($key, $count);
    }

    public static function calendarRunTotal(ConnectedAccount $account): int
    {
        return max(0, (int) Cache::get(self::calendarTotalKey($account), 0));
    }

    public static function bumpCalendarProcessed(ConnectedAccount $account): void
    {
        Cache::increment(self::calendarProcessedKey($account));
    }

    public static function calendarProcessedCount(ConnectedAccount $account): int
    {
        return (int) Cache::get(self::calendarProcessedKey($account), 0);
    }

    public static function isCalendarSyncing(ConnectedAccount $account): bool
    {
        return Cache::has(self::calendarKey($account));
    }

    public static function markEmailStarted(ConnectedAccount $account): void
    {
        Cache::put(self::emailKey($account), true, now()->addMinutes(self::TTL_MINUTES));
        Cache::put(self::emailProcessedKey($account), 0, now()->addMinutes(self::TTL_MINUTES));
        Cache::forget(self::emailTotalKey($account));
    }

    public static function markEmailFinished(ConnectedAccount $account): void
    {
        Cache::forget(self::emailKey($account));
        Cache::forget(self::emailProcessedKey($account));
        Cache::forget(self::emailTotalKey($account));
    }

    public static function setEmailRunTotal(ConnectedAccount $account, int $total): void
    {
        Cache::put(self::emailTotalKey($account), $total, now()->addMinutes(self::TTL_MINUTES));
    }

    public static function bumpEmailProcessed(ConnectedAccount $account): void
    {
        Cache::increment(self::emailProcessedKey($account));
    }

    public static function emailProcessedCount(ConnectedAccount $account): int
    {
        return (int) Cache::get(self::emailProcessedKey($account), 0);
    }

    public static function runProgressPercent(ConnectedAccount $account): int
    {
        $done = 0;
        $total = 0;

        if (self::isEmailSyncing($account)) {
            $emailTotal = Cache::get(self::emailTotalKey($account));

            if (is_int($emailTotal) && $emailTotal > 0) {
                $done += self::emailProcessedCount($account);
                $total += $emailTotal;
            }
        }

        if (self::isCalendarSyncing($account)) {
            $calendarTotal = self::calendarRunTotal($account);

            if ($calendarTotal > 0) {
                $done += self::calendarProcessedCount($account);
                $total += $calendarTotal;
            }
        }

        if ($total <= 0) {
            return 0;
        }

        return min(100, (int) round(($done / $total) * 100));
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

    private static function emailProcessedKey(ConnectedAccount $account): string
    {
        return 'mailbox-sync:email:'.$account->getKey().':processed';
    }

    private static function emailTotalKey(ConnectedAccount $account): string
    {
        return 'mailbox-sync:email:'.$account->getKey().':total';
    }

    private static function calendarProcessedKey(ConnectedAccount $account): string
    {
        return 'mailbox-sync:calendar:'.$account->getKey().':processed';
    }

    private static function calendarTotalKey(ConnectedAccount $account): string
    {
        return 'mailbox-sync:calendar:'.$account->getKey().':total';
    }

    private static function calendarGenerationKey(ConnectedAccount $account): string
    {
        return 'mailbox-sync:calendar:'.$account->getKey().':generation';
    }
}
