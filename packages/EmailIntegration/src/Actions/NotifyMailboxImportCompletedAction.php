<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\Cache;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

final readonly class NotifyMailboxImportCompletedAction
{
    public function execute(ConnectedAccount $account): void
    {
        $account = $account->fresh() ?? $account;

        if (! $this->isInitialImportComplete($account)) {
            return;
        }

        $cacheKey = 'mailbox-import-notified:'.$account->getKey();

        if (! Cache::add($cacheKey, true, now()->addDays(30))) {
            return;
        }

        $account->user?->notify(new MailboxHistoryImportCompletedNotification($account));
    }

    private function isInitialImportComplete(ConnectedAccount $account): bool
    {
        if ($account->sync_cursor === null) {
            return false;
        }

        return ! $account->hasCalendar() || ! MailboxSyncTracker::isCalendarSyncing($account);
    }
}
