<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\NotifyMailboxImportCompletedAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

mutates(NotifyMailboxImportCompletedAction::class);

beforeEach(function (): void {
    Notification::fake();
});

it('notifies the owner when email import finishes and calendar is not enabled', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-1',
        'initial_sync_imported' => 142,
        'initial_calendar_sync_imported' => 0,
    ]));

    resolve(NotifyMailboxImportCompletedAction::class)->execute($account);

    Notification::assertSentTo(
        $account->user,
        MailboxHistoryImportCompletedNotification::class,
    );
});

it('waits for calendar import before notifying when calendar is enabled', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-1',
        'calendar_sync_cursor' => 'delta-1',
        'capabilities' => ['email' => true, 'send' => true, 'calendar' => true],
        'initial_sync_imported' => 142,
        'initial_calendar_sync_imported' => 6,
    ]));

    MailboxSyncTracker::markCalendarStarted($account);

    resolve(NotifyMailboxImportCompletedAction::class)->execute($account);

    Notification::assertNothingSent();
});

it('notifies once calendar import finishes', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-1',
        'calendar_sync_cursor' => 'delta-1',
        'capabilities' => ['email' => true, 'send' => true, 'calendar' => true],
        'initial_sync_imported' => 142,
        'initial_calendar_sync_imported' => 6,
    ]));

    resolve(NotifyMailboxImportCompletedAction::class)->execute($account);

    Notification::assertSentTo(
        $account->user,
        MailboxHistoryImportCompletedNotification::class,
    );
});

it('does not notify twice for the same mailbox import', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-1',
        'initial_sync_imported' => 10,
    ]));

    resolve(NotifyMailboxImportCompletedAction::class)->execute($account);
    resolve(NotifyMailboxImportCompletedAction::class)->execute($account);

    Notification::assertSentTimes(MailboxHistoryImportCompletedNotification::class, 1);
});

it('does not notify before email import finishes', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => null,
        'initial_sync_imported' => 0,
    ]));

    resolve(NotifyMailboxImportCompletedAction::class)->execute($account);

    Notification::assertNothingSent();

    expect(Cache::has('mailbox-import-notified:'.$account->getKey()))->toBeFalse();
});
