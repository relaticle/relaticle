<?php

declare(strict_types=1);

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Testing\Fakes\BatchFake;
use Laravel\SerializableClosure\SerializableClosure;
use Relaticle\EmailIntegration\Data\CalendarEventData;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

mutates(IncrementalCalendarSyncJob::class);

it('resets cursor and dispatches initial sync on 410', function (): void {
    Bus::fake([InitialCalendarSyncJob::class]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'stale',
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('fetchDelta')
        ->andThrow(CalendarSyncTokenExpired::forAccount($account->getKey()));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new IncrementalCalendarSyncJob($account))->handle($factory);

    expect($account->fresh()?->calendar_sync_cursor)->toBeNull();
    Bus::assertDispatched(InitialCalendarSyncJob::class);
});

it('batches a StoreMeetingJob per delta event and does not advance the cursor until the page stores', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'valid-token',
        'last_calendar_synced_at' => null,
    ]));

    $event = new CalendarEventData(
        providerEventId: 'evt-delta',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Delta event',
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
    $service->shouldReceive('fetchDelta')->once()->with('valid-token')
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: 'new-token'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new IncrementalCalendarSyncJob($account))->handle($factory);

    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->queue() === 'emails-sync'
        && $batch->jobs->count() === 1
        && $batch->jobs->first() instanceof StoreMeetingJob
    );
    expect($account->fresh()?->calendar_sync_cursor)->toBe('valid-token')
        ->and($account->fresh()?->last_calendar_synced_at)->toBeNull();
});

it('advances the cursor after the store batch completes', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'valid-token',
    ]));

    $event = new CalendarEventData(
        providerEventId: 'evt-delta',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Delta event',
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
    $service->shouldReceive('fetchDelta')->once()->with('valid-token')
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: 'new-token'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new IncrementalCalendarSyncJob($account))->handle($factory);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        foreach ($batch->finallyCallbacks() as $callback) {
            $closure = $callback instanceof SerializableClosure ? $callback->getClosure() : $callback;
            $closure(new BatchFake(
                id: 'batch-1',
                name: 'Incremental calendar sync',
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

    expect($account->fresh()?->calendar_sync_cursor)->toBe('new-token')
        ->and($account->fresh()?->last_calendar_synced_at)->not->toBeNull();
});

it('advances the cursor immediately when the delta has no events', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'valid-token',
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('fetchDelta')->once()->with('valid-token')
        ->andReturn(new CalendarSyncResult(events: [], nextSyncToken: 'new-token'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new IncrementalCalendarSyncJob($account))->handle($factory);

    Bus::assertNothingBatched();
    expect($account->fresh()?->calendar_sync_cursor)->toBe('new-token')
        ->and($account->fresh()?->last_calendar_synced_at)->not->toBeNull();
});

it('does not overwrite the cursor when an empty delta returns a null token', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'valid-token',
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('fetchDelta')->once()->with('valid-token')
        ->andReturn(new CalendarSyncResult(events: [], nextSyncToken: null));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new IncrementalCalendarSyncJob($account))->handle($factory);

    Bus::assertNothingBatched();
    expect($account->fresh()?->calendar_sync_cursor)->toBe('valid-token');
});

it('dispatches initial sync when no cursor exists', function (): void {
    Bus::fake([InitialCalendarSyncJob::class]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => null,
    ]));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldNotReceive('make');

    (new IncrementalCalendarSyncJob($account))->handle($factory);

    Bus::assertDispatched(InitialCalendarSyncJob::class);
});

it('clears the calendar sync badge when delegating to initial sync without starting incremental sync', function (): void {
    Bus::fake([InitialCalendarSyncJob::class]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => null,
    ]));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldNotReceive('make');

    (new IncrementalCalendarSyncJob($account))->handle($factory);

    expect(MailboxSyncTracker::isCalendarSyncing($account))->toBeFalse();
});

it('records a batch failure, holds the cursor, and clears the calendar sync badge', function (): void {
    Bus::fake();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'valid-token',
    ]));

    $event = new CalendarEventData(
        providerEventId: 'evt-delta',
        providerRecurringEventId: null,
        iCalUid: null,
        title: 'Delta event',
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
    $service->shouldReceive('fetchDelta')->once()->with('valid-token')
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: 'new-token'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new IncrementalCalendarSyncJob($account))->handle($factory);

    Bus::assertBatched(function (PendingBatch $batch): bool {
        foreach ($batch->finallyCallbacks() as $callback) {
            $closure = $callback instanceof SerializableClosure ? $callback->getClosure() : $callback;
            $closure(new BatchFake(
                id: 'batch-1',
                name: 'Incremental calendar sync',
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

    expect($account->fresh()?->calendar_sync_cursor)->toBe('valid-token')
        ->and($account->fresh()?->status)->toBe(EmailAccountStatus::ERROR)
        ->and($account->fresh()?->last_error)->toContain('1 calendar event(s)')
        ->and(MailboxSyncTracker::isCalendarSyncing($account))->toBeFalse();
});
