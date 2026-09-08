<?php

declare(strict_types=1);

use Relaticle\EmailIntegration\Actions\ReconcileCalendarMeetingsAction;
use Relaticle\EmailIntegration\Exceptions\ReconcileCalendarMeetingsFailed;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;

mutates(ReconcileCalendarMeetingsAction::class);

it('reports reconciliation failures on the connected account', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('listActiveProviderEventIds')
        ->once()
        ->andThrow(new RuntimeException('Graph unavailable'));

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->with($account)->andReturn($service);

    expect(fn () => resolve(ReconcileCalendarMeetingsAction::class, ['calendarFactory' => $factory])->execute($account))
        ->toThrow(ReconcileCalendarMeetingsFailed::class);
});

it('removes meetings that no longer exist on the provider calendar', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    $stale = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'stale-event',
    ]);
    $current = Meeting::factory()->create([
        'connected_account_id' => $account->getKey(),
        'team_id' => $account->team_id,
        'provider_event_id' => 'live-event',
    ]);

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('listActiveProviderEventIds')
        ->once()
        ->andReturn(['live-event']);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->with($account)->andReturn($service);

    resolve(ReconcileCalendarMeetingsAction::class, ['calendarFactory' => $factory])->execute($account);

    expect($stale->fresh()?->trashed())->toBeTrue()
        ->and($current->fresh()?->trashed())->toBeFalse();
});
