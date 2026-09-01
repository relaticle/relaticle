<?php

declare(strict_types=1);

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;

mutates(InitialEmailSyncJob::class);

it('does not cap the first import when EMAIL_SYNC_INITIAL_DAYS is unset', function (): void {
    Bus::fake();
    Notification::fake();
    config()->set('email-integration.sync.initial_days', null);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')
        ->once()
        ->with(null, null)
        ->andReturn(new MailBackfillPage(
            messageIds: collect(),
            nextPageToken: null,
            cursor: 'history-1',
        ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new InitialEmailSyncJob($account))->handle($factory);

    expect($account->fresh()?->sync_cursor)->toBe('history-1');
});

it('passes the optional day cap through to the mail service', function (): void {
    Bus::fake();
    Notification::fake();
    config()->set('email-integration.sync.initial_days', 90);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')
        ->once()
        ->with(90, null)
        ->andReturn(new MailBackfillPage(
            messageIds: collect(),
            nextPageToken: null,
            cursor: 'history-1',
        ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new InitialEmailSyncJob($account))->handle($factory);
});

it('batches one page of messages and leaves the cursor unset until the page stores', function (): void {
    Bus::fake();
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn(new MailBackfillPage(
        messageIds: collect(['M1', 'M2']),
        nextPageToken: 'page-2',
        cursor: 'history-1',
        estimatedTotal: 80,
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new InitialEmailSyncJob($account))->handle($factory);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->queue() === 'emails-sync'
        && $batch->jobs->count() === 2
        && $batch->jobs->every(fn ($job): bool => $job instanceof StoreEmailJob)
    );
    expect($account->fresh()?->sync_cursor)->toBeNull()
        ->and($account->fresh()?->initial_sync_estimated)->toBe(80);

    Notification::assertNothingSent();
});

it('chains the next page when the current page has no new ids', function (): void {
    Bus::fake();
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn(new MailBackfillPage(
        messageIds: collect(),
        nextPageToken: 'page-2',
        cursor: 'history-1',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new InitialEmailSyncJob($account))->handle($factory);

    Bus::assertDispatched(
        InitialEmailSyncJob::class,
        fn (InitialEmailSyncJob $job): bool => $job->pageToken === 'page-2'
            && $job->historyCursor === 'history-1',
    );
    expect($account->fresh()?->sync_cursor)->toBeNull();
    Notification::assertNothingSent();
});

it('sets the cursor and notifies the owner when the last page is stored', function (): void {
    Bus::fake();
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn(new MailBackfillPage(
        messageIds: collect(),
        nextPageToken: null,
        cursor: 'history-1',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new InitialEmailSyncJob($account))->handle($factory);

    expect($account->fresh()?->sync_cursor)->toBe('history-1')
        ->and($account->fresh()?->last_synced_at)->not->toBeNull();

    Notification::assertSentTo(
        $account->user,
        MailboxHistoryImportCompletedNotification::class,
    );
});
