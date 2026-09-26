<?php

declare(strict_types=1);

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Testing\Fakes\BatchFake;
use Laravel\SerializableClosure\SerializableClosure;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialSyncPageStoreBatch;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

mutates(InitialCalendarSyncJob::class, InitialSyncPageStoreBatch::class);

function invokeInitialCalendarSyncBatchFinallyCallbacks(): void
{
    Bus::assertBatched(function (PendingBatch $batch): bool {
        foreach ($batch->finallyCallbacks() as $callback) {
            $closure = $callback instanceof SerializableClosure ? $callback->getClosure() : $callback;
            $closure(new BatchFake(
                id: 'batch-1',
                name: 'Initial calendar sync',
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
}

it('batches a StoreMeetingJob per event and does not advance the cursor until the page stores', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $event = new CalendarEventData(
        providerEventId: 'evt-A',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Test',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->with(null)
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: 'token-xyz'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->queue() === 'emails-sync'
        && $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof StoreMeetingJob
    );
    expect($account->fresh()?->calendar_sync_cursor)->toBeNull();
});

it('serializes the store-batch continuation without the running queue worker', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $event = new CalendarEventData(
        providerEventId: 'evt-A',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Test',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->with(null)
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: null, nextPageToken: 'page-2'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    $syncJob = new InitialCalendarSyncJob($account);
    $syncJob->job = new class
    {
        public mixed $resource;

        public function __construct()
        {
            $this->resource = fopen('php://memory', 'r');
        }
    };

    $syncJob->handle($factory);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        expect($batch->thenCallbacks())->toBeEmpty()
            ->and($batch->finallyCallbacks())->not->toBeEmpty();

        foreach ($batch->finallyCallbacks() as $callback) {
            serialize($callback);
        }

        return true;
    });
});

it('chains the next calendar page after the store batch completes', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $event = new CalendarEventData(
        providerEventId: 'evt-A',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Test',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->with(null)
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: null, nextPageToken: 'page-2'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
        'provider_event_id' => 'evt-A',
    ]);

    invokeInitialCalendarSyncBatchFinallyCallbacks();

    Bus::assertDispatched(
        InitialCalendarSyncJob::class,
        fn (InitialCalendarSyncJob $job): bool => $job->pageToken === 'page-2',
    );
    expect($account->fresh()?->calendar_sync_cursor)->toBeNull();
});

it('preserves requested reconciliation when chaining the next calendar page', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [], nextSyncToken: null, nextPageToken: 'page-2'));
    $service->shouldNotReceive('listActiveProviderEventIds');

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account, reconcileAfter: true))->handle($factory);

    Bus::assertDispatched(
        InitialCalendarSyncJob::class,
        fn (InitialCalendarSyncJob $job): bool => $job->pageToken === 'page-2' && $job->reconcileAfter,
    );
    expect($account->fresh()?->calendar_sync_cursor)->toBeNull();
});

it('removes meetings absent from the provider when the initial sync requested reconciliation', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $stale = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
        'provider_event_id' => 'deleted-on-provider',
    ]);
    $current = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
        'provider_event_id' => 'still-on-provider',
    ]);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [], nextSyncToken: 'token-xyz'));
    $service->shouldReceive('listActiveProviderEventIds')->once()->andReturn(['still-on-provider']);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    (new InitialCalendarSyncJob($account, reconcileAfter: true))->handle($factory);

    expect($stale->fresh()?->trashed())->toBeTrue()
        ->and($current->fresh()?->trashed())->toBeFalse()
        ->and($account->fresh()?->calendar_sync_cursor)->toBe('token-xyz');
});

it('removes meetings absent from the provider after the last store batch completes', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $stale = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
        'provider_event_id' => 'deleted-on-provider',
    ]);

    $event = new CalendarEventData(
        providerEventId: 'still-on-provider',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Still there',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: 'token-batch'));
    $service->shouldReceive('listActiveProviderEventIds')->once()->andReturn(['still-on-provider']);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    (new InitialCalendarSyncJob($account, reconcileAfter: true))->handle($factory);

    $current = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
        'provider_event_id' => 'still-on-provider',
    ]);

    invokeInitialCalendarSyncBatchFinallyCallbacks();

    expect($stale->fresh()?->trashed())->toBeTrue()
        ->and($current->fresh()?->trashed())->toBeFalse()
        ->and($account->fresh()?->calendar_sync_cursor)->toBe('token-batch');
});

it('does not reconcile meetings when the initial sync did not request it', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $stored = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
        'provider_event_id' => 'locally-stored',
    ]);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [], nextSyncToken: 'token-xyz'));
    $service->shouldNotReceive('listActiveProviderEventIds');

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    expect($stored->fresh()?->trashed())->toBeFalse();
});

it('records a reconciliation failure after the initial sync finishes', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [], nextSyncToken: 'token-xyz'));
    $service->shouldReceive('listActiveProviderEventIds')->once()
        ->andThrow(new RuntimeException('Graph unavailable'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->andReturn($service);
    app()->instance(CalendarServiceFactoryInterface::class, $factory);

    (new InitialCalendarSyncJob($account, reconcileAfter: true))->handle($factory);

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::ERROR)
        ->and($account->fresh()?->last_error)->toStartWith('Calendar reconciliation failed:')
        ->and($account->fresh()?->calendar_sync_cursor)->toBe('token-xyz');
});

it('stores the sync token immediately when the last page has no events', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [], nextSyncToken: 'token-xyz'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    Bus::assertNothingBatched();
    expect($account->fresh()?->calendar_sync_cursor)->toBe('token-xyz')
        ->and($account->fresh()?->initial_calendar_sync_imported)->toBe(0);
});

it('batches skipped events for cleanup without waiting for them to persist', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $events = [
        new CalendarEventData(
            providerEventId: 'private-event', providerRecurringEventId: null, iCalUid: null,
            title: 'Private', description: null, startsAt: Date::now()->addDay(), endsAt: Date::now()->addDay()->addHour(),
            isAllDay: false, location: null, htmlLink: null, status: 'confirmed', visibility: 'private',
            organizerEmail: null, organizerName: null, attendees: [],
        ),
        new CalendarEventData(
            providerEventId: 'cancelled-event', providerRecurringEventId: null, iCalUid: null,
            title: 'Cancelled', description: null, startsAt: Date::now()->addDays(2), endsAt: Date::now()->addDays(2)->addHour(),
            isAllDay: false, location: null, htmlLink: null, status: 'cancelled', visibility: 'default',
            organizerEmail: null, organizerName: null, attendees: [],
        ),
        new CalendarEventData(
            providerEventId: 'confidential-event', providerRecurringEventId: null, iCalUid: null,
            title: 'Confidential', description: null, startsAt: Date::now()->addDays(3), endsAt: Date::now()->addDays(3)->addHour(),
            isAllDay: false, location: null, htmlLink: null, status: 'confirmed', visibility: 'confidential',
            organizerEmail: null, organizerName: null, attendees: [],
        ),
    ];

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: $events, nextSyncToken: 'token-xyz'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 3
        && $batch->jobs->every(fn (object $job): bool => $job instanceof StoreMeetingJob));

    invokeInitialCalendarSyncBatchFinallyCallbacks();

    expect($account->fresh()?->calendar_sync_cursor)->toBe('token-xyz');
});

it('writes the stored meeting count when the initial calendar backfill finishes', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    Meeting::factory()->count(2)->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
    ]);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [], nextSyncToken: 'token-xyz'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    expect($account->fresh()?->initial_calendar_sync_imported)->toBe(2);
});

it('chains the next calendar page instead of advancing the cursor', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [], nextSyncToken: null, nextPageToken: 'page-2'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    Bus::assertDispatched(
        InitialCalendarSyncJob::class,
        fn (InitialCalendarSyncJob $job): bool => $job->pageToken === 'page-2',
    );
    expect($account->fresh()?->calendar_sync_cursor)->toBeNull();
});

it('skips accounts without calendar capability', function (): void {
    Bus::fake([StoreMeetingJob::class]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => false],
    ]));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldNotReceive('make');

    (new InitialCalendarSyncJob($account))->handle($factory);

    Bus::assertNotDispatched(StoreMeetingJob::class);
});

it('stores the sync token when the batch completion callback runs', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $event = new CalendarEventData(
        providerEventId: 'evt-callback',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Callback',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: 'token-batch'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'workspace_id' => $account->workspace_id,
        'provider_event_id' => 'evt-callback',
    ]);

    invokeInitialCalendarSyncBatchFinallyCallbacks();

    expect($account->fresh()?->calendar_sync_cursor)->toBe('token-batch')
        ->and($account->fresh()?->last_calendar_synced_at)->not->toBeNull();
});

it('keeps the mailbox active and continues calendar import when page events cannot be stored', function (): void {
    Bus::fake();
    config()->set('email-integration.sync.initial_store_attempts', 1);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $events = [
        new CalendarEventData(
            providerEventId: 'evt-A',
            providerRecurringEventId: null,
            iCalUid: null,
            title: 'A',
            description: null,
            startsAt: Date::now()->addDay(),
            endsAt: Date::now()->addDay()->addHour(),
            isAllDay: false,
            location: null,
            htmlLink: null,
            status: 'confirmed',
            visibility: 'default',
            organizerEmail: null,
            organizerName: null,
            attendees: [],
        ),
        new CalendarEventData(
            providerEventId: 'evt-B',
            providerRecurringEventId: null,
            iCalUid: null,
            title: 'B',
            description: null,
            startsAt: Date::now()->addDays(2),
            endsAt: Date::now()->addDays(2)->addHour(),
            isAllDay: false,
            location: null,
            htmlLink: null,
            status: 'confirmed',
            visibility: 'default',
            organizerEmail: null,
            organizerName: null,
            attendees: [],
        ),
    ];

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: $events, nextSyncToken: null, nextPageToken: 'page-2'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    invokeInitialCalendarSyncBatchFinallyCallbacks();

    Bus::assertDispatched(
        InitialCalendarSyncJob::class,
        fn (InitialCalendarSyncJob $job): bool => $job->pageToken === 'page-2',
    );
    expect($account->fresh()?->calendar_sync_cursor)->toBeNull()
        ->and($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()?->last_error)->toBeNull()
        ->and($account->fresh()?->hasSyncError())->toBeFalse();
});

it('records unstorable calendar events as import issues instead of a sync error', function (): void {
    Bus::fake();
    config()->set('email-integration.sync.initial_store_attempts', 1);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'sync_cursor' => 'history-done',
    ]));
    $batchId = attachHistoryImportBatch($account);

    $event = new CalendarEventData(
        providerEventId: 'evt-missing',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Missing',
        description: null,
        startsAt: Date::now()->addDay(),
        endsAt: Date::now()->addDay()->addHour(),
        isAllDay: false,
        location: null,
        htmlLink: null,
        status: 'confirmed',
        visibility: 'default',
        organizerEmail: null,
        organizerName: null,
        attendees: [],
    );

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('initialSync')->once()
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: 'token-after-skip'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new InitialCalendarSyncJob($account))->handle($factory);

    invokeInitialCalendarSyncBatchFinallyCallbacks();

    expect($account->fresh()?->status)->toBe(EmailAccountStatus::ACTIVE)
        ->and($account->fresh()?->last_error)->toBeNull()
        ->and($account->fresh()?->calendar_sync_cursor)->toBeNull()
        ->and($account->fresh()?->hasSyncError())->toBeFalse()
        ->and(resolve(MailboxHistoryImportService::class)->calendarFailureCount($batchId))->toBe(1)
        ->and($account->fresh()?->showsMailboxHistoryImportFailureSummary())->toBeTrue();
});
