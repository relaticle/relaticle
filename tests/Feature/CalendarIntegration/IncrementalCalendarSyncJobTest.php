<?php

declare(strict_types=1);

use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Data\CalendarSyncResult;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreMeetingJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\Exceptions\CalendarSyncTokenExpired;

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

it('dispatches StoreMeetingJob per delta event and updates cursor', function (): void {
    Bus::fake([StoreMeetingJob::class]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'valid-token',
    ]));

    $event = new Event;
    $event->setId('evt-delta');
    $start = new EventDateTime;
    $start->setDateTime(now()->addDay()->toRfc3339String());
    $end = new EventDateTime;
    $end->setDateTime(now()->addDay()->addHour()->toRfc3339String());
    $event->setStart($start);
    $event->setEnd($end);
    $event->setSummary('Delta event');
    $event->setStatus('confirmed');
    $event->setVisibility('default');

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('fetchDelta')->once()->with('valid-token')
        ->andReturn(new CalendarSyncResult(events: [$event], nextSyncToken: 'new-token'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->andReturn($service);

    (new IncrementalCalendarSyncJob($account))->handle($factory);

    Bus::assertDispatched(StoreMeetingJob::class, 1);
    expect($account->fresh()?->calendar_sync_cursor)->toBe('new-token');
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
