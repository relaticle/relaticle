<?php

declare(strict_types=1);

use App\Models\User;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\BatchFake;
use Laravel\SerializableClosure\SerializableClosure;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Exceptions\MailHistoryExpired;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\ProviderRateLimit;

mutates(IncrementalEmailSyncJob::class);

/**
 * @param  array<string, mixed>  $deltaOverrides
 */
function runIncrementalSync(ConnectedAccount $account, array $deltaOverrides): void
{
    Queue::fake();

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchDelta')->andReturn(new MailDeltaResult(
        messageIds: $deltaOverrides['messageIds'] ?? collect([]),
        readMessageIds: $deltaOverrides['readMessageIds'] ?? collect([]),
        newCursor: 'next-cursor',
        unreadMessageIds: $deltaOverrides['unreadMessageIds'] ?? null,
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(MailServiceFactoryInterface::class, $factory);

    (new IncrementalEmailSyncJob($account))->handle($factory);
}

function syncableAccount(): ConnectedAccount
{
    $user = User::factory()->withWorkspace()->create();

    return ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'access_token' => 'a',
            'refresh_token' => 'r',
            'token_expires_at' => now()->addHour(),
            'sync_cursor' => 'old-cursor',
            'status' => EmailAccountStatus::ACTIVE,
            'capabilities' => ['email' => true, 'calendar' => false],
        ]);
}

it('batches StoreEmailJob for new Microsoft messages and defers the cursor to the batch callback', function (): void {
    Bus::fake();

    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'access_token' => 'a',
            'refresh_token' => 'r',
            'token_expires_at' => now()->addHour(),
            'sync_cursor' => 'old-cursor',
            'status' => EmailAccountStatus::ACTIVE,
            'capabilities' => ['email' => true, 'calendar' => false],
        ]);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchDelta')->with('old-cursor')->andReturn(new MailDeltaResult(
        messageIds: collect(['M1', 'M2']),
        readMessageIds: collect([]),
        newCursor: 'new-cursor',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->with(Mockery::on(fn (ConnectedAccount $a): bool => $a->is($account)))->andReturn($service);
    $this->app->instance(MailServiceFactoryInterface::class, $factory);

    (new IncrementalEmailSyncJob($account))->handle($factory);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 2
        && $batch->jobs->every(fn (object $job): bool => $job instanceof StoreEmailJob));

    // The cursor must NOT advance until the batch's then() callback runs — otherwise an
    // unstored message is skipped forever.
    expect($account->refresh()->sync_cursor)->toBe('old-cursor');
});

it('advances the cursor after the store batch completes', function (): void {
    Bus::fake();

    $account = syncableAccount();

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchDelta')->with('old-cursor')->andReturn(new MailDeltaResult(
        messageIds: collect(['M1']),
        readMessageIds: collect([]),
        newCursor: 'new-cursor',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    $this->app->instance(MailServiceFactoryInterface::class, $factory);

    (new IncrementalEmailSyncJob($account))->handle($factory);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        foreach ($batch->finallyCallbacks() as $callback) {
            $closure = $callback instanceof SerializableClosure ? $callback->getClosure() : $callback;
            $closure(new BatchFake(
                id: 'batch-1',
                name: 'Incremental sync',
                totalJobs: $batch->jobs->count(),
                pendingJobs: 0,
                failedJobs: 0,
                failedJobIds: [],
                options: [],
                createdAt: now()->toImmutable(),
            ));
        }

        return true;
    });

    expect($account->refresh()->sync_cursor)->toBe('new-cursor');
});

it('does not advance the cursor when the store batch fails', function (): void {
    Bus::fake();

    $account = syncableAccount();

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchDelta')->with('old-cursor')->andReturn(new MailDeltaResult(
        messageIds: collect(['M1']),
        readMessageIds: collect([]),
        newCursor: 'new-cursor',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    $this->app->instance(MailServiceFactoryInterface::class, $factory);

    (new IncrementalEmailSyncJob($account))->handle($factory);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        foreach ($batch->finallyCallbacks() as $callback) {
            $closure = $callback instanceof SerializableClosure ? $callback->getClosure() : $callback;
            $closure(new BatchFake(
                id: 'batch-1',
                name: 'Incremental sync',
                totalJobs: $batch->jobs->count(),
                pendingJobs: 0,
                failedJobs: 1,
                failedJobIds: ['job-1'],
                options: [],
                createdAt: now()->toImmutable(),
            ));
        }

        return true;
    });

    expect($account->refresh()->sync_cursor)->toBe('old-cursor')
        ->and($account->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->last_error)->toContain('1 email(s)');
});

it('keeps a mailbox that failed to store messages eligible for the scheduled sync', function (): void {
    $account = syncableAccount();
    $account->update(['last_error' => '1 email(s) could not be stored during sync.']);

    Bus::fake();

    $this->artisan('email:incremental-sync')->assertSuccessful();

    Bus::assertDispatched(
        IncrementalEmailSyncJob::class,
        fn (IncrementalEmailSyncJob $job): bool => $job->connectedAccount->is($account),
    );
});

it('advances the cursor inline when the delta has no new messages', function (): void {
    Bus::fake();

    $account = syncableAccount();

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchDelta')->andReturn(new MailDeltaResult(
        messageIds: collect([]),
        readMessageIds: collect([]),
        newCursor: 'fresh-cursor',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    $this->app->instance(MailServiceFactoryInterface::class, $factory);

    (new IncrementalEmailSyncJob($account))->handle($factory);

    Bus::assertNothingBatched();
    expect($account->refresh()->sync_cursor)->toBe('fresh-cursor');
});

it('records the owner read state when the provider marks a message read', function (): void {
    Queue::fake();

    $account = syncableAccount();

    $email = Email::factory()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->user_id,
        'connected_account_id' => $account->getKey(),
        'provider_message_id' => 'MSG-READ',
    ]);

    runIncrementalSync($account, ['readMessageIds' => collect(['MSG-READ'])]);

    $this->assertDatabaseHas('email_reads', [
        'email_id' => $email->getKey(),
        'user_id' => $account->user_id,
    ]);
});

it('removes the owner read state when the provider marks a message unread', function (): void {
    Queue::fake();

    $account = syncableAccount();

    $email = Email::factory()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->user_id,
        'connected_account_id' => $account->getKey(),
        'provider_message_id' => 'MSG-UNREAD',
    ]);

    EmailRead::factory()->create([
        'email_id' => $email->getKey(),
        'user_id' => $account->user_id,
    ]);

    runIncrementalSync($account, ['unreadMessageIds' => collect(['MSG-UNREAD'])]);

    $this->assertDatabaseMissing('email_reads', [
        'email_id' => $email->getKey(),
        'user_id' => $account->user_id,
    ]);
});

it('starts a fresh history import batch when mailbox history has expired', function (): void {
    Bus::fake([InitialEmailSyncJob::class, RelinkMailboxHistoryJob::class]);

    $account = syncableAccount();
    $account->update(['history_import_batch_id' => 'stale-batch-id']);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchDelta')
        ->once()
        ->with('old-cursor')
        ->andThrow(MailHistoryExpired::forAccount((string) $account->getKey()));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new IncrementalEmailSyncJob($account))->handle($factory);

    $account->refresh();

    expect($account->sync_cursor)->toBeNull()
        ->and($account->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->history_import_batch_id)->not->toBe('stale-batch-id')
        ->and($account->history_import_batch_id)->not->toBeNull();

    Bus::assertDispatched(
        InitialEmailSyncJob::class,
        fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($account)
            && $job->historyImportBatchId === $account->history_import_batch_id,
    );
});

it('releases instead of failing when the provider rate limits the delta listing', function (): void {
    Queue::fake();
    $account = syncableAccount();

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchDelta')->andThrow(new GoogleServiceException(json_encode([
        'error' => ['code' => 429, 'message' => 'User-rate limit exceeded.', 'errors' => [['reason' => 'rateLimitExceeded']]],
    ], JSON_THROW_ON_ERROR), 429));
    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('release')->once();

    $job = new IncrementalEmailSyncJob($account);
    $job->setJob($queueJob);
    $job->handle($factory);

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and(ProviderRateLimit::remainingSeconds((string) $account->getKey()))->toBeGreaterThan(0);
});
