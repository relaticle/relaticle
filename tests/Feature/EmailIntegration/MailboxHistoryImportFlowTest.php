<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Bus\PendingBatch;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\CompleteMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Actions\RecordMailboxHistoryImportStoreFailureAction;
use Relaticle\EmailIntegration\Actions\RetryMailboxHistoryImportFailuresAction;
use Relaticle\EmailIntegration\Actions\StartMailboxHistoryImportAction;
use Relaticle\EmailIntegration\Actions\StoreEmailAction;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

mutates(
    CompleteMailboxHistoryImportAction::class,
    ConnectedAccount::class,
    EmailAccessNotificationHandler::class,
    InitialEmailSyncJob::class,
    RecordMailboxHistoryImportStoreFailureAction::class,
    RetryMailboxHistoryImportFailuresAction::class,
    StartMailboxHistoryImportAction::class,
    MailboxHistoryImportService::class,
    StoreEmailJob::class,
);

it('uses allowFailures on the history import batch', function (): void {
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    Bus::fake([]);

    resolve(StartMailboxHistoryImportAction::class)->execute($account);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->allowsFailures()
        && $batch->jobs->count() === 0);
});

it('adds store jobs to the import batch and skips messages already stored', function (): void {
    Notification::fake();
    Queue::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    attachHistoryImportBatch($account);

    Email::factory()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->user_id,
        'connected_account_id' => $account->getKey(),
        'provider_message_id' => 'M-existing',
    ]);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')
        ->once()
        ->with(null, null)
        ->andReturn(new MailBackfillPage(
            messageIds: collect(['M-existing', 'M-new']),
            nextPageToken: null,
            cursor: 'history-1',
        ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    app()->call(
        [new InitialEmailSyncJob($account, historyImportBatchId: $account->history_import_batch_id), 'handle'],
        ['mailFactory' => $factory],
    );

    $batch = Bus::findBatch((string) $account->history_import_batch_id);

    expect($batch?->totalJobs)->toBe(1);
});

it('does not cancel the import batch when a store job fails', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    $batchId = attachHistoryImportBatch($account);

    $job = new StoreEmailJob($account, 'msg-fail');
    $job->withBatchId($batchId);
    $job->failed(new RuntimeException('Provider timeout'));

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()?->last_error)->toBeNull()
        ->and(resolve(MailboxHistoryImportService::class)->failureGeneration($batchId))->toBe(1);
});

it('does not record last_error when a store job fails outside the history import batch', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'history_import_batch_id' => 'history-batch',
    ]));
    $batch = Bus::batch([])->allowFailures()->dispatch();

    $job = new StoreEmailJob($account, 'msg-fail');
    $job->withBatchId($batch->id);
    $job->failed(new RuntimeException('Provider timeout'));

    expect($account->fresh()?->last_error)->toBeNull();
});

it('reports batch summary totals after the import batch finishes', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 2,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2']),
        'finished_at' => null,
    ]);

    $summary = resolve(MailboxHistoryImportService::class)->summary($account->fresh());

    expect($summary?->totalJobs)->toBe(10)
        ->and($summary?->successfulJobs)->toBe(8)
        ->and($summary?->failedJobs)->toBe(2)
        ->and($summary?->finished)->toBeTrue();
});

it('counts distinct failed batch jobs for the summary not cumulative retry failures', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 5,
        'pending_jobs' => 1,
        'failed_jobs' => 4,
        'failed_job_ids' => json_encode(['failed-uuid-1']),
        'finished_at' => now()->getTimestamp(),
    ]);

    $summary = resolve(MailboxHistoryImportService::class)->summary($account->fresh());

    expect($summary?->failedJobs)->toBe(1)
        ->and($summary?->successfulJobs)->toBe(4);
});

it('treats the import batch as finished when all jobs are done but some failed', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 0,
        'failed_jobs' => 3,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2', 'failed-3']),
        'finished_at' => null,
    ]);

    $service = resolve(MailboxHistoryImportService::class);

    expect($service->summary($account->fresh())?->finished)->toBeTrue()
        ->and($account->fresh()?->isEmailHistoryImportRunning())->toBeFalse()
        ->and($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeTrue();
});

it('treats the import batch as finished when failed jobs remain pending in job_batches', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 2,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2']),
        'finished_at' => null,
    ]);

    $service = resolve(MailboxHistoryImportService::class);

    expect($service->summary($account->fresh())?->finished)->toBeTrue()
        ->and($account->fresh()?->isEmailHistoryImportRunning())->toBeFalse();
});

it('re-import history creates a new batch and resets the mailbox cursor', function (): void {
    Bus::fake([RelinkMailboxHistoryJob::class, InitialEmailSyncJob::class]);
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'done',
        'history_import_batch_id' => 'old-batch',
    ]));

    resolve(StartMailboxHistoryImportAction::class)->execute($account->fresh());

    expect($account->fresh())
        ->sync_cursor->toBeNull()
        ->and($account->fresh()?->history_import_batch_id)->not->toBe('old-batch');

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->allowsFailures());
});

it('re-import dispatches store jobs only for messages missing locally', function (): void {
    Notification::fake();
    Queue::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'done',
    ]));

    Email::factory()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->user_id,
        'connected_account_id' => $account->getKey(),
        'provider_message_id' => 'M1',
    ]);

    attachHistoryImportBatch($account);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn(new MailBackfillPage(
        messageIds: collect(['M1', 'M2']),
        nextPageToken: null,
        cursor: 'history-2',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    app()->call(
        [new InitialEmailSyncJob($account, historyImportBatchId: $account->history_import_batch_id), 'handle'],
        ['mailFactory' => $factory],
    );

    expect(Bus::findBatch((string) $account->history_import_batch_id)?->totalJobs)->toBe(1);
});

it('blocks duplicate re-import history requests for the same mailbox', function (): void {
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => null,
    ]));

    attachHistoryImportBatch($account);

    expect(fn () => resolve(StartMailboxHistoryImportAction::class)->execute($account->fresh()))
        ->toThrow(RuntimeException::class);
});

it('restarts history import after pagination exhausts retries and no jobs remain', function (): void {
    Bus::fake([RelinkMailboxHistoryJob::class, InitialEmailSyncJob::class]);
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => null,
    ]));
    $staleBatchId = attachHistoryImportBatch($account);

    (new InitialEmailSyncJob($account, historyImportBatchId: $staleBatchId))
        ->failed(new RuntimeException('Provider 500'));

    resolve(StartMailboxHistoryImportAction::class)->execute($account->fresh());

    expect($account->fresh())
        ->status->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()?->history_import_batch_id)->not->toBe($staleBatchId)
        ->and($account->fresh()?->history_import_batch_id)->not->toBeNull();

    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('still blocks re-import while store jobs remain after listing fails', function (): void {
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => null,
    ]));
    $batchId = attachHistoryImportBatch($account);

    (new InitialEmailSyncJob($account, historyImportBatchId: $batchId))
        ->failed(new RuntimeException('Provider 500'));

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 4,
        'pending_jobs' => 2,
        'failed_jobs' => 0,
        'finished_at' => null,
    ]);

    expect(fn () => resolve(StartMailboxHistoryImportAction::class)->execute($account->fresh()))
        ->toThrow(RuntimeException::class);
});

it('restarts history import after drained store jobs leave an active mailbox without a cursor', function (): void {
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => null,
        'status' => EmailAccountStatus::ACTIVE,
        'last_error' => null,
    ]));
    $staleBatchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $staleBatchId)->update([
        'total_jobs' => 4,
        'pending_jobs' => 0,
        'failed_jobs' => 0,
        'finished_at' => now()->getTimestamp(),
    ]);

    expect(resolve(MailboxHistoryImportService::class)->isRunning($account->fresh()))->toBeFalse();

    Bus::fake([RelinkMailboxHistoryJob::class, InitialEmailSyncJob::class]);

    resolve(StartMailboxHistoryImportAction::class)->execute($account->fresh());

    expect($account->fresh())
        ->status->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()?->history_import_batch_id)->not->toBe($staleBatchId)
        ->and($account->fresh()?->history_import_batch_id)->not->toBeNull();

    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('blocks concurrent re-import history starts with a cache lock', function (): void {
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'done',
    ]));

    $lock = Cache::lock(resolve(MailboxHistoryImportService::class)->lockKey($account), 60);
    $lock->get();

    try {
        expect(fn () => resolve(StartMailboxHistoryImportAction::class)->execute($account))
            ->toThrow(RuntimeException::class);
    } finally {
        $lock->release();
    }
});

it('does not create duplicate email rows when store runs twice for the same provider message', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_inbox' => true,
        'sync_sent' => true,
    ]));

    $payload = new FetchedEmailData(
        providerMessageId: 'dup-msg',
        threadId: 'thread-1',
        rfcMessageId: '<dup@example.com>',
        inReplyTo: null,
        subject: 'Duplicate',
        snippet: 'Duplicate',
        bodyText: 'Duplicate',
        bodyHtml: '<p>Duplicate</p>',
        direction: EmailDirection::INBOUND,
        folder: EmailFolder::Inbox,
        sentAt: now(),
        isRead: true,
        hasAttachments: false,
        participants: [],
        attachments: [],
    );

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')->once()->andReturn($payload);

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    $action = resolve(StoreEmailAction::class);

    (new StoreEmailJob($account, 'dup-msg'))->handle($factory, $action);
    (new StoreEmailJob($account, 'dup-msg'))->handle($factory, $action);

    expect(Email::query()->where('connected_account_id', $account->getKey())->count())->toBe(1);
});

it('registers withoutOverlapping middleware on store email jobs', function (): void {
    $account = ConnectedAccount::factory()->make();
    $job = new StoreEmailJob($account, 'msg-1');
    $middleware = $job->middleware()[0];

    expect($job->middleware())->toHaveCount(1)
        ->and($middleware)->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware->releaseAfter)->toBe(15)
        ->and($middleware->expiresAfter)->toBe(300);
});

it('finishes pagination while the import batch continues processing', function (): void {
    Notification::fake();
    Queue::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    attachHistoryImportBatch($account);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn(new MailBackfillPage(
        messageIds: collect(['M1']),
        nextPageToken: null,
        cursor: 'history-1',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    app()->call(
        [new InitialEmailSyncJob($account, historyImportBatchId: $account->history_import_batch_id), 'handle'],
        ['mailFactory' => $factory],
    );

    expect($account->fresh()?->sync_cursor)->toBe('history-1')
        ->and($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()?->isEmailHistoryImportRunning())->toBeTrue();
});

it('does not surface the failure summary while store jobs are still running', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-1',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 3,
        'failed_jobs' => 1,
        'finished_at' => null,
    ]);

    expect($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeFalse()
        ->and($account->fresh()?->showsMailboxHistoryImportProgressOnAccountsPage())->toBeTrue();
});

it('uses batch progress percent while store jobs still run', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 224,
        'pending_jobs' => 3,
        'failed_jobs' => 0,
        'finished_at' => null,
    ]);

    expect($account->fresh()?->isEmailHistoryImportRunning())->toBeTrue()
        ->and($account->fresh()?->isImportingHistory())->toBeFalse()
        ->and($account->fresh()?->syncDisplayPercent())->toBe(99);
});

it('does not keep the activation checklist in syncing after the mailbox cursor is written', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 224,
        'pending_jobs' => 3,
        'failed_jobs' => 0,
        'finished_at' => null,
    ]);

    expect($account->fresh()?->isImportingHistory())->toBeFalse()
        ->and($account->fresh()?->showsMailboxHistoryImportProgressOnAccountsPage())->toBeTrue();
});

it('does not treat an empty history batch as running after listing finishes', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
        'initial_sync_estimated' => 100,
        'initial_sync_imported' => 99,
    ]));
    attachHistoryImportBatch($account);

    $service = resolve(MailboxHistoryImportService::class);

    expect($account->fresh()?->isEmailHistoryImportRunning())->toBeFalse()
        ->and($service->progressPercent($account->fresh()))->toBe(100);
});

it('does not show a false 99 percent while listing before any store jobs exist', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'initial_sync_estimated' => 100,
        'initial_sync_imported' => 99,
    ]));
    attachHistoryImportBatch($account);

    expect(resolve(MailboxHistoryImportService::class)->progressPercent($account))->toBe(0);
});

it('bases import percent on jobs finished while any store work is still pending', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 2,
        'failed_jobs' => 2,
        'finished_at' => null,
    ]);

    $service = resolve(MailboxHistoryImportService::class);

    expect($service->processedJobCount($account->fresh()))->toBe(8)
        ->and($service->progressPercent($account->fresh()))->toBe(80);
});

it('reflects successful store jobs when some batch jobs failed permanently', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 100,
        'pending_jobs' => 2,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-uuid-1', 'failed-uuid-2']),
        'finished_at' => null,
    ]);

    expect(resolve(MailboxHistoryImportService::class)->progressPercent($account->fresh()))->toBe(98);
});

it('does not show one hundred percent on the estimate path while listing is still running', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => null,
        'initial_sync_estimated' => 501,
        'initial_sync_imported' => 636,
    ]));

    expect($account->initialSyncProgressPercent())->toBe(99);
});

it('caps batch progress below one hundred percent while provider listing is still running', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => null,
    ]));
    $batchId = attachHistoryImportBatch($account);
    $service = resolve(MailboxHistoryImportService::class);
    $service->markEmailListingStarted($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 50,
        'pending_jobs' => 0,
        'failed_jobs' => 0,
        'finished_at' => null,
    ]);

    expect($service->progressPercent($account->fresh()))->toBe(99);
});

it('does not treat history import store failures as a mailbox sync error', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
        'last_error' => 'This message could not be stored after several tries.',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 5,
        'pending_jobs' => 0,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-1']),
        'finished_at' => now()->getTimestamp(),
    ]);

    expect($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($account->fresh()?->showsHomeMailboxImportProgress())->toBeFalse()
        ->and($account->fresh()?->hasSyncError())->toBeFalse();
});

it('uses store batch progress while jobs are still pending after listing finishes', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 100,
        'pending_jobs' => 25,
        'failed_jobs' => 0,
        'finished_at' => null,
    ]);

    expect(resolve(MailboxHistoryImportService::class)->progressPercent($account->fresh()))->toBe(75);
});

it('retries only failed jobs from the history import batch', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->current_workspace_id,
        'sync_cursor' => 'done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 2,
        'pending_jobs' => 0,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-uuid-1']),
        'finished_at' => now()->getTimestamp(),
    ]);

    insertHistoryImportFailedJob($account, $batchId, 'failed-uuid-1');
    fakeHistoryImportQueueRetry('failed-uuid-1');

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($account->user, $account, $batchId))->toBeTrue();
});

it('bumps the import issue dismiss token when a store failure is recorded again', function (): void {
    Cache::flush();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
        'history_import_batch_id' => 'batch-dismiss-token',
    ]));

    DB::table('job_batches')->insert([
        'id' => 'batch-dismiss-token',
        'name' => 'Mailbox history import',
        'total_jobs' => 1,
        'pending_jobs' => 0,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-uuid']),
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => now()->getTimestamp(),
        'finished_at' => now()->getTimestamp(),
    ]);

    $action = resolve(RecordMailboxHistoryImportStoreFailureAction::class);

    $action->execute($account, 'batch-dismiss-token');

    expect($account->fresh()->mailboxHistoryImportFailureDismissToken())->toBe('batch-dismiss-token:1');

    $action->execute($account->fresh(), 'batch-dismiss-token');

    expect($account->fresh()->mailboxHistoryImportFailureDismissToken())->toBe('batch-dismiss-token:2');
});

it('surfaces the failure summary after the import batch finishes with failed jobs', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-1',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 10,
        'pending_jobs' => 2,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2']),
        'finished_at' => now()->getTimestamp(),
    ]);

    expect($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($account->fresh()?->showsMailboxHistoryImportProgressOnAccountsPage())->toBeFalse();
});

it('keeps the failure summary visible after retry is queued while store jobs rerun', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 3,
        'pending_jobs' => 1,
        'failed_jobs' => 0,
        'failed_job_ids' => json_encode([]),
        'finished_at' => null,
    ]);

    resolve(MailboxHistoryImportService::class)->markAwaitingRetrySuccessNotice($batchId);

    expect($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($account->fresh()?->isMailboxHistoryImportRetryQueued())->toBeTrue()
        ->and($account->fresh()?->showsSyncProgressOnAccountsPage())->toBeFalse()
        ->and($account->fresh()?->showsMailboxHistoryImportPercent())->toBeFalse()
        ->and($account->fresh()?->syncDisplayPercent())->toBe(0);
});

it('waits for pagination before notifying even when a page finishes storing early', function (): void {
    Queue::fake();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());
    $batchId = attachHistoryImportBatch($account);
    $batch = Bus::findBatch($batchId);
    $batch->add([new StoreEmailJob($account, 'early-message')]);
    $batch->recordSuccessfulJob('early-job');

    expect($account->user->notifications()->count())->toBe(0);
    Queue::assertNotPushed(SendQueuedNotifications::class);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->once()->andReturn(new MailBackfillPage(
        messageIds: collect(), nextPageToken: null, cursor: 'history-done',
    ));
    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    app()->call([new InitialEmailSyncJob($account, historyImportBatchId: $batchId), 'handle'], ['mailFactory' => $factory]);

    expect($account->user->notifications()->sole()->data['status'])->toBe('success');
});

it('notifies after retry when every failed import job eventually succeeds', function (): void {
    Queue::fake();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    resolve(MailboxHistoryImportService::class)->markAwaitingRetrySuccessNotice($batchId);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 2,
        'pending_jobs' => 0,
        'failed_jobs' => 0,
        'failed_job_ids' => json_encode([]),
        'finished_at' => now()->getTimestamp(),
    ]);

    resolve(CompleteMailboxHistoryImportAction::class)
        ->execute((string) $account->getKey(), $batchId);

    $notification = $account->user->notifications()->sole();

    expect($notification->data['status'])->toBe('success')
        ->and($notification->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.retry_success.title'));

    Queue::assertPushed(SendQueuedNotifications::class);
});

it('notifies with issues when the import batch finishes with failed jobs', function (): void {
    Queue::fake();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);
    $batch = Bus::findBatch($batchId);
    $batch->add([new StoreEmailJob($account, 'first'), new StoreEmailJob($account, 'second')]);
    $batch->recordFailedJob('failed-job', new RuntimeException('Provider unavailable'));

    expect($account->user->notifications()->count())->toBe(0);

    $batch->recordSuccessfulJob('successful-job');

    expect($account->fresh()->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue();

    $notification = $account->user->notifications()->sole();

    expect($notification->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title_with_issues'))
        ->and($notification->data['status'])->toBe('warning')
        ->and($notification->data['viewData']['kind'])->toBe('partial')
        ->and($notification->data['body'])->toContain("1 message couldn't be imported")
        ->and(collect($notification->data['actions'] ?? [])->pluck('name')->all())->toBe(['retry']);

    Queue::assertPushed(SendQueuedNotifications::class);

    resolve(MailboxHistoryImportService::class)->markAwaitingRetrySuccessNotice($batchId);

    foreach ($batch->options['finally'] as $callback) {
        $callback($batch->fresh());
    }

    expect($account->user->notifications()->count())->toBe(1)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue();
});

it('retries a mailbox import from the notification action', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id, 'workspace_id' => $user->current_workspace_id,
        'sync_cursor' => 'done',
    ]));
    $batchId = attachHistoryImportBatch($account);
    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 1, 'pending_jobs' => 0, 'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['failed-job']),
        'finished_at' => now()->getTimestamp(),
    ]);
    insertHistoryImportFailedJob($account, $batchId, 'failed-job');
    fakeHistoryImportQueueRetry('failed-job');

    Livewire::test(EmailAccessNotificationHandler::class)
        ->dispatch('retry-mailbox-history-import', accountId: (string) $account->getKey(), batchId: $batchId)
        ->assertNotified(__('filament/notifications/mailbox-import-complete.retry.queued.title'));

    expect(resolve(MailboxHistoryImportService::class)->hasAwaitingRetrySuccessNotice($batchId))->toBeTrue();
});

it('resolves failed store job uuids from the failed jobs table when batch ids are empty', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->current_workspace_id,
        'sync_cursor' => 'history-done',
        'history_import_batch_id' => 'batch-from-failed-jobs',
    ]));

    DB::table('job_batches')->insert([
        'id' => 'batch-from-failed-jobs',
        'name' => 'Mailbox history import',
        'total_jobs' => 1,
        'pending_jobs' => 0,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode([]),
        'options' => serialize([]),
        'cancelled_at' => null,
        'created_at' => now()->getTimestamp(),
        'finished_at' => now()->getTimestamp(),
    ]);

    insertHistoryImportFailedJob($account, 'batch-from-failed-jobs', 'failed-uuid-from-table', 'msg-failed');
    fakeHistoryImportQueueRetry('failed-uuid-from-table');

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)
        ->execute($user, $account, 'batch-from-failed-jobs'))->toBeTrue();
});

it('recovers the failed email through the notification retry using the real queue retry command', function (): void {
    config()->set('queue.default', 'database');
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id, 'workspace_id' => $user->current_workspace_id,
        'sync_cursor' => 'done', 'sync_inbox' => true,
        'last_error' => 'This message could not be stored after several tries.',
    ]));
    $batchId = attachHistoryImportBatch($account);
    $import = resolve(MailboxHistoryImportService::class);
    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')->once()->ordered()->andThrow(new RuntimeException('Provider unavailable'));
    $service->shouldReceive('fetchMessage')->once()->ordered()->andReturn(new FetchedEmailData(
        providerMessageId: 'retry-message', threadId: 'retry-thread', rfcMessageId: '<retry@example.com>',
        inReplyTo: null, subject: 'Quarterly review', snippet: 'Quarterly review',
        bodyText: 'Quarterly review', bodyHtml: '<p>Quarterly review</p>',
        direction: EmailDirection::INBOUND, folder: EmailFolder::Inbox,
        sentAt: now(), isRead: true, hasAttachments: false, participants: [], attachments: [],
    ));
    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);

    $job = new StoreEmailJob($account, 'retry-message');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'emails-sync', '--once' => true]);

    expect($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and($account->emails()->count())->toBe(0)
        ->and($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(1)
        ->and($user->notifications()->sole()->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title_with_issues'));

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), $batchId))->toBeTrue();

    expect($account->fresh()->last_error)->toBeNull();
    expect($import->hasAwaitingRetrySuccessNotice($batchId))->toBeTrue();
    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), $batchId))->toBeFalse();
    expect($import->hasAwaitingRetrySuccessNotice($batchId))->toBeTrue();
    expect($user->notifications()->count())->toBe(1);

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'emails-sync', '--once' => true]);

    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect($account->emails()->sole()->provider_message_id)->toBe('retry-message');
    expect(Bus::findBatch($batchId)->failedJobIds)->toBe([]);
    expect($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeFalse();
    expect($import->hasAwaitingRetrySuccessNotice($batchId))->toBeFalse();
    expect($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(2);
    $importNotifications = $user->notifications()
        ->where('type', MailboxHistoryImportCompletedNotification::class)
        ->get();
    expect($importNotifications->pluck('data.title')->all())
        ->toContain(__('filament/notifications/mailbox-import-complete.title_with_issues'))
        ->toContain(__('filament/notifications/mailbox-import-complete.retry_success.title'));
    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), $batchId))->toBeFalse();
    expect($user->notifications()->count())->toBe(2);

    livewire(EmailAccountsPage::class)
        ->assertDontSee(__('filament/pages/email-accounts.history_import_failure.badge'))
        ->assertSee(__('filament/pages/email-accounts.in_sync'));
});

it('does not send a retry-success notice until every failed import job succeeds', function (): void {
    config()->set('queue.default', 'database');
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id, 'workspace_id' => $user->current_workspace_id,
        'sync_cursor' => 'done', 'sync_inbox' => true,
    ]));
    $batchId = attachHistoryImportBatch($account);
    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchMessage')->once()->ordered()->andThrow(new RuntimeException('Provider unavailable'));
    $service->shouldReceive('fetchMessage')->once()->ordered()->andThrow(new RuntimeException('Provider unavailable'));
    $service->shouldReceive('fetchMessage')->once()->ordered()->andReturn(new FetchedEmailData(
        providerMessageId: 'retry-twice-message', threadId: 'retry-twice-thread', rfcMessageId: '<retry-twice@example.com>',
        inReplyTo: null, subject: 'Quarterly review', snippet: 'Quarterly review',
        bodyText: 'Quarterly review', bodyHtml: '<p>Quarterly review</p>',
        direction: EmailDirection::INBOUND, folder: EmailFolder::Inbox,
        sentAt: now(), isRead: true, hasAttachments: false, participants: [], attachments: [],
    ));
    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);

    $job = new StoreEmailJob($account, 'retry-twice-message');
    $job->tries = 1;
    Bus::findBatch($batchId)->add([$job]);
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'emails-sync', '--once' => true]);

    expect($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(1)
        ->and($user->notifications()->sole()->data['title'])->toBe(__('filament/notifications/mailbox-import-complete.title_with_issues'));

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), $batchId))->toBeTrue();

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'emails-sync', '--once' => true]);

    expect($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeTrue()
        ->and($account->emails()->count())->toBe(0)
        ->and($user->notifications()->count())->toBe(1);

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account->fresh(), $batchId))->toBeTrue();

    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'emails-sync', '--once' => true]);

    expect($account->emails()->sole()->provider_message_id)->toBe('retry-twice-message');
    expect($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeFalse();
    expect($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->count())->toBe(2);
    expect($user->notifications()->where('type', MailboxHistoryImportCompletedNotification::class)->get()->pluck('data.title')->all())
        ->toContain(__('filament/notifications/mailbox-import-complete.retry_success.title'));
});

it('does not treat a missing failed job record as retried', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $user->current_workspace_id,
        'sync_cursor' => 'history-done',
        'last_error' => 'This message could not be stored after several tries.',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 1,
        'pending_jobs' => 0,
        'failed_jobs' => 1,
        'failed_job_ids' => json_encode(['missing-uuid']),
        'finished_at' => now()->getTimestamp(),
    ]);

    expect(resolve(RetryMailboxHistoryImportFailuresAction::class)->execute($user, $account, $batchId))->toBeFalse();
    expect($account->fresh()->last_error)->toBe('This message could not be stored after several tries.');
    expect(resolve(MailboxHistoryImportService::class)->hasAwaitingRetrySuccessNotice($batchId))->toBeFalse();
});

it('does not surface an import issue after resolved failures leave an empty failed job id list', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    DB::table('job_batches')->where('id', $batchId)->update([
        'total_jobs' => 3,
        'pending_jobs' => 0,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode([]),
        'finished_at' => now()->getTimestamp(),
    ]);

    $summary = resolve(MailboxHistoryImportService::class)->summary($account->fresh());

    expect($summary?->failedJobs)->toBe(0)
        ->and($account->fresh()->showsMailboxHistoryImportFailureSummary())->toBeFalse();
});
