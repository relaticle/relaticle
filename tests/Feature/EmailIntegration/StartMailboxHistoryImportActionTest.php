<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Actions\StartMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Enums\MailboxImportPass;
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

it('starts a Microsoft mailbox with the recent pass on the sync queue', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->azure()->create());

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertDispatched(fn (InitialEmailSyncJob $job): bool => $job->pass === MailboxImportPass::Recent
        && $job->queue === 'emails-sync'
        && $job->historyImportBatchId === $account->fresh()?->history_import_batch_id);
});

it('imports a Gmail mailbox in one pass, because Gmail returns its cursor with the first page', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertDispatched(fn (InitialEmailSyncJob $job): bool => $job->pass === MailboxImportPass::Full && $job->queue === null);
});

it('imports in one pass when the history cap is no wider than the recent window', function (int $initialDays): void {
    Bus::fake();
    config()->set('email-integration.sync.initial_days', $initialDays);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->azure()->create());

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertDispatched(fn (InitialEmailSyncJob $job): bool => $job->pass === MailboxImportPass::Full && $job->queue === null);
})->with([
    'cap equals the window' => [90],
    'cap inside the window' => [30],
]);

it('keeps the recent pass when the history cap is wider than the window', function (): void {
    Bus::fake();
    config()->set('email-integration.sync.initial_days', 365);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->azure()->create());

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertDispatched(fn (InitialEmailSyncJob $job): bool => $job->pass === MailboxImportPass::Recent);
});

it('keeps the import running after new mail starts syncing, until the history is listed', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    resolve(StartMailboxHistoryImportAction::class)->execute($account);
    $account->refresh()->update(['sync_cursor' => 'live-cursor']);

    expect($account->fresh()?->isEmailHistoryImportRunning())->toBeTrue();
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
    expect(MailboxSyncTracker::isCalendarSyncing($account))->toBeTrue();
});

it('queues an email backfill when a mailbox cursor already exists', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'mail-cursor',
        'initial_sync_estimated' => 80,
        'calendar_sync_cursor' => 'calendar-cursor',
        'capabilities' => [
            'email' => true,
            'calendar' => true,
        ],
    ]));

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
    Bus::assertNotDispatched(IncrementalEmailSyncJob::class);
    Bus::assertDispatched(fn (IncrementalCalendarSyncJob $job): bool => $job->connectedAccount->is($account) && $job->reconcileAfter);
    Bus::assertNotDispatched(InitialCalendarSyncJob::class);

    expect($account->fresh())
        ->sync_cursor->toBeNull()
        ->initial_sync_estimated->toBeNull()
        ->calendar_sync_cursor->toBe('calendar-cursor');

    expect(MailboxSyncTracker::isCalendarSyncing($account))->toBeTrue()
        ->and(MailboxSyncTracker::isEmailSyncing($account))->toBeFalse();
});
