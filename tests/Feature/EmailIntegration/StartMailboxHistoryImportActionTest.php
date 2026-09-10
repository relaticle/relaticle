<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Actions\StartMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

mutates(StartMailboxHistoryImportAction::class);

it('queues relink and initial email sync for an active account', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertDispatched(RelinkMailboxHistoryJob::class, fn (RelinkMailboxHistoryJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertNotDispatched(InitialCalendarSyncJob::class);
});

it('also queues initial calendar sync when the account has calendar', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'capabilities' => [
            'email' => true,
            'calendar' => true,
        ],
    ]));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertDispatched(InitialCalendarSyncJob::class, fn (InitialCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('queues incremental sync when calendar and mailbox cursors already exist', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'mail-cursor',
        'calendar_sync_cursor' => 'calendar-cursor',
        'capabilities' => [
            'email' => true,
            'calendar' => true,
        ],
    ]));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertDispatched(IncrementalEmailSyncJob::class, fn (IncrementalEmailSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertDispatched(fn (IncrementalCalendarSyncJob $job): bool => $job->connectedAccount->is($account) && $job->reconcileAfter);
    Bus::assertNotDispatched(InitialEmailSyncJob::class);
    Bus::assertNotDispatched(InitialCalendarSyncJob::class);

    expect(MailboxSyncTracker::isCalendarSyncing($account))->toBeTrue();
});
