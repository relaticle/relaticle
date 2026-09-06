<?php

declare(strict_types=1);

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Laravel\SerializableClosure\SerializableClosure;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;

mutates(InitialCalendarSyncJob::class);

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
        startsAt: Carbon::now()->addDay(),
        endsAt: Carbon::now()->addDay()->addHour(),
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
        startsAt: Carbon::now()->addDay(),
        endsAt: Carbon::now()->addDay()->addHour(),
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
        foreach ($batch->thenCallbacks() as $callback) {
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
        startsAt: Carbon::now()->addDay(),
        endsAt: Carbon::now()->addDay()->addHour(),
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

    Bus::assertBatched(function (PendingBatch $batch): bool {
        foreach ($batch->thenCallbacks() as $callback) {
            $callback();
        }

        return true;
    });

    Bus::assertDispatched(
        InitialCalendarSyncJob::class,
        fn (InitialCalendarSyncJob $job): bool => $job->pageToken === 'page-2',
    );
    expect($account->fresh()?->calendar_sync_cursor)->toBeNull();
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

it('writes the stored meeting count when the initial calendar backfill finishes', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    Meeting::factory()->count(2)->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
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
        startsAt: Carbon::now()->addDay(),
        endsAt: Carbon::now()->addDay()->addHour(),
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

    Bus::assertBatched(function (PendingBatch $batch): bool {
        foreach ($batch->thenCallbacks() as $callback) {
            $closure = $callback instanceof SerializableClosure ? $callback->getClosure() : $callback;
            $closure();
        }

        return true;
    });

    expect($account->fresh()?->calendar_sync_cursor)->toBe('token-batch')
        ->and($account->fresh()?->last_calendar_synced_at)->not->toBeNull();
});
