<?php

declare(strict_types=1);

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Laravel\SerializableClosure\SerializableClosure;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;

mutates(
    IncrementalEmailSyncJob::class,
    InitialEmailSyncJob::class,
    StoreEmailJob::class,
    IncrementalCalendarSyncJob::class,
    InitialCalendarSyncJob::class,
    StoreMeetingJob::class,
    RelinkMailboxHistoryJob::class,
);

function queueRoutingCalendarEvent(): CalendarEventData
{
    return new CalendarEventData(
        providerEventId: 'evt-callback',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Callback test',
        description: null,
        startsAt: Carbon::now(),
        endsAt: Carbon::now()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );
}

it('routes inbound email and calendar sync jobs to emails-sync queue', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $event = new CalendarEventData(
        providerEventId: 'evt-queue',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Queue test',
        description: null,
        startsAt: Carbon::now(),
        endsAt: Carbon::now()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );

    expect((new IncrementalEmailSyncJob($account))->queue)->toBe('emails-sync')
        ->and((new InitialEmailSyncJob($account))->queue)->toBe('emails-sync')
        ->and((new StoreEmailJob($account, 'msg-1'))->queue)->toBe('emails-sync')
        ->and((new IncrementalCalendarSyncJob($account))->queue)->toBe('emails-sync')
        ->and((new InitialCalendarSyncJob($account))->queue)->toBe('emails-sync')
        ->and((new StoreMeetingJob($account, $event))->queue)->toBe('emails-sync')
        ->and((new RelinkMailboxHistoryJob($account))->queue)->toBe('emails-sync');
});

it('dispatches the initial-sync StoreEmailJob batch onto the emails-sync queue', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn(new MailBackfillPage(
        messageIds: collect(['M1', 'M2']),
        nextPageToken: null,
        cursor: 'cursor-1',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new InitialEmailSyncJob($account))->handle($factory);

    // Bus::batch() ignores each job's constructor onQueue() — without an explicit
    // ->onQueue() on the batch the StoreEmailJobs leak onto the default queue.
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->queue() === 'emails-sync'
        && $batch->jobs->count() === 2
    );
});

function assertBatchCallbacksCarryNoJobInstance(): void
{
    Bus::assertBatched(function (PendingBatch $batch): bool {
        expect($batch->thenCallbacks())->not->toBeEmpty();

        foreach ($batch->thenCallbacks() as $callback) {
            $closure = $callback instanceof SerializableClosure ? $callback->getClosure() : $callback;

            expect((new ReflectionFunction($closure))->getClosureThis())->toBeNull();
        }

        return true;
    });
}

it('keeps the job instance out of the initial email sync batch callback', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('initialBackfill')->andReturn(new MailBackfillPage(
        messageIds: collect(['M1']),
        nextPageToken: null,
        cursor: 'cursor-1',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new InitialEmailSyncJob($account))->handle($factory);

    assertBatchCallbacksCarryNoJobInstance();
});

it('keeps the job instance out of the incremental email sync batch callback', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'old-cursor',
    ]));

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchDelta')->andReturn(new MailDeltaResult(
        messageIds: collect(['M1']),
        readMessageIds: collect([]),
        newCursor: 'next-cursor',
        unreadMessageIds: null,
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new IncrementalEmailSyncJob($account))->handle($factory);

    assertBatchCallbacksCarryNoJobInstance();
});

it('keeps the job instance out of the initial calendar sync batch callback', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->andReturn(new CalendarSyncResult(
        events: [queueRoutingCalendarEvent()],
        nextSyncToken: 'token-xyz',
    ));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    assertBatchCallbacksCarryNoJobInstance();
});

it('keeps redis retry_after above every worker timeout on that connection', function (): void {
    $supervisors = collect(config('horizon.defaults'))
        ->merge(collect(config('horizon.environments'))->flatMap(fn (array $environment): array => $environment));

    $longestTimeout = $supervisors
        ->filter(fn (array $supervisor): bool => ($supervisor['connection'] ?? null) === 'redis')
        ->map(fn (array $supervisor): int => (int) ($supervisor['timeout'] ?? 60))
        ->max();

    expect(config('queue.connections.redis.retry_after'))->toBeGreaterThan($longestTimeout);
});
