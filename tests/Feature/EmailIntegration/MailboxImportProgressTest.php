<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

mutates(EmailAccountsPage::class, ConnectedAccount::class, MailboxHistoryImportService::class, MailboxSyncTracker::class);

it('shows import progress while the mailbox cursor has not been written', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => null,
    ]));
    setHistoryImportBatchProgress(attachHistoryImportBatch($account), 40, 28);

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing'))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 30]))
        ->assertSee('role="progressbar"', false)
        ->assertSee('aria-valuenow="30"', false)
        ->assertSee('aria-valuemax="100"', false)
        ->assertSee('motion-safe:animate-spin', false);
});

it('shows in sync after the mailbox cursor is written', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => 'history-1',
        'last_synced_at' => now(),
    ]));

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.in_sync'))
        ->assertDontSee(__('filament/pages/email-accounts.importing'));
});

it('picks up a new batch percent when the accounts list refreshes', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => null,
    ]));
    $batchId = attachHistoryImportBatch($account);
    setHistoryImportBatchProgress($batchId, 387, 387);

    $page = livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 0]));

    DB::table('job_batches')->where('id', $batchId)->update([
        'pending_jobs' => 363,
    ]);

    $page->call('refreshAccounts')
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 6]))
        ->assertSee('aria-valuenow="6"', false);
});

it('shows 0% until store jobs exist on the history import batch', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => null,
        'initial_sync_imported' => 8,
        'initial_sync_estimated' => 100,
    ]));
    attachHistoryImportBatch($account);

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing'))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 0]))
        ->assertDontSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 8]))
        ->assertSee('role="progressbar"', false)
        ->assertSee('aria-valuenow="0"', false)
        ->assertSee('motion-safe:animate-spin', false);
});

it('keeps import progress below 100% while calendar history continues after email listing finishes', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => 'history-done',
        'calendar_sync_cursor' => null,
        'initial_sync_imported' => 40,
        'initial_sync_estimated' => 40,
    ]));
    $batchId = attachHistoryImportBatch($account);
    setHistoryImportBatchProgress($batchId, 40, 0);
    DB::table('job_batches')->where('id', $batchId)->update([
        'finished_at' => now()->getTimestamp(),
    ]);

    resolve(MailboxHistoryImportService::class)->markCalendarImportPending($batchId);
    MailboxSyncTracker::markCalendarStarted($account);

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing'))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 50]))
        ->assertSee('aria-valuenow="50"', false)
        ->assertDontSee('aria-valuenow="100"', false);
});

it('combines email and calendar as equal halves of the history import percent', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => 'history-done',
        'calendar_sync_cursor' => null,
        'initial_calendar_sync_imported' => 25,
    ]));
    $batchId = attachHistoryImportBatch($account);
    setHistoryImportBatchProgress($batchId, 100, 50);
    DB::table('job_batches')->where('id', $batchId)->update([
        'finished_at' => null,
    ]);

    $import = resolve(MailboxHistoryImportService::class);
    $import->markCalendarImportPending($batchId);
    $import->addCalendarDiscovered($batchId, 50);
    MailboxSyncTracker::markCalendarStarted($account);
    MailboxSyncTracker::setCalendarRunTotal($account, 50);

    for ($i = 0; $i < 25; $i++) {
        MailboxSyncTracker::bumpCalendarProcessed($account);
    }

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing'))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 50]))
        ->assertSee('aria-valuenow="50"', false);
});

it('does not drop history import percent when more calendar events are discovered', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => 'history-done',
        'calendar_sync_cursor' => null,
    ]));
    $batchId = attachHistoryImportBatch($account);
    setHistoryImportBatchProgress($batchId, 100, 0);
    DB::table('job_batches')->where('id', $batchId)->update([
        'finished_at' => now()->getTimestamp(),
    ]);

    $import = resolve(MailboxHistoryImportService::class);
    $import->markCalendarImportPending($batchId);
    $import->addCalendarDiscovered($batchId, 40);
    MailboxSyncTracker::markCalendarStarted($account);
    MailboxSyncTracker::setCalendarRunTotal($account, 40);

    for ($i = 0; $i < 40; $i++) {
        MailboxSyncTracker::bumpCalendarProcessed($account);
    }

    expect($import->progressPercent($account->fresh()))->toBe(99);

    $import->addCalendarDiscovered($batchId, 60);
    MailboxSyncTracker::addCalendarRunTotal($account, 60);

    expect($import->progressPercent($account->fresh()))->toBe(99);
});

it('counts a failed store job once when reporting import progress', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => null,
    ]));
    $batchId = attachHistoryImportBatch($account);
    setHistoryImportBatchProgress($batchId, 10, 4);
    DB::table('job_batches')->where('id', $batchId)->update([
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2']),
    ]);

    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 60]));
});

it('treats a batch whose only pending jobs failed again after a retry as complete', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => 'history-1',
    ]));
    $batchId = attachHistoryImportBatch($account);
    setHistoryImportBatchProgress($batchId, 10, 3);
    DB::table('job_batches')->where('id', $batchId)->update([
        'failed_jobs' => 7,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2', 'failed-3']),
    ]);

    expect(resolve(MailboxHistoryImportService::class)->isRunning($account->fresh()))->toBeFalse();
});
