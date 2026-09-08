<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Actions\DisconnectConnectedAccountAction;
use Relaticle\EmailIntegration\Actions\StopCalendarPushChannelAction;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;

mutates(DisconnectConnectedAccountAction::class, StopCalendarPushChannelAction::class);

it('stops and clears calendar push fields when disconnecting an account', function (): void {
    Http::fake([
        'https://graph.microsoft.com/v1.0/subscriptions/sub-123' => Http::response(null, 204),
    ]);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->azure()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_push_channel_id' => 'sub-123',
        'calendar_push_resource_id' => null,
        'calendar_push_verification_token' => 'secret',
        'calendar_push_expires_at' => now()->addDay(),
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('stopPushChannel')
        ->once()
        ->with('sub-123', null);

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->with($account)->andReturn($service);

    resolve(DisconnectConnectedAccountAction::class, [
        'stopCalendarPushChannel' => new StopCalendarPushChannelAction($factory),
    ])->execute($account);

    expect($account->fresh()?->trashed())->toBeTrue()
        ->and($account->fresh()?->calendar_push_channel_id)->toBeNull()
        ->and($account->fresh()?->calendar_push_verification_token)->toBeNull();
});

it('stops and clears calendar push fields when disabling calendar sync', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'provider' => EmailProvider::GMAIL,
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_push_channel_id' => 'channel-123',
        'calendar_push_resource_id' => 'resource-123',
        'calendar_push_verification_token' => 'secret',
        'calendar_push_expires_at' => now()->addDay(),
    ]));

    $service = Mockery::mock(CalendarServiceInterface::class);
    $service->shouldReceive('stopPushChannel')
        ->once()
        ->with('channel-123', 'resource-123');

    $factory = Mockery::mock(CalendarServiceFactoryInterface::class);
    $factory->shouldReceive('make')->once()->with($account)->andReturn($service);

    resolve(StopCalendarPushChannelAction::class, ['calendarFactory' => $factory])->execute($account);

    $account->refresh();

    expect($account->calendar_push_channel_id)->toBeNull()
        ->and($account->calendar_push_resource_id)->toBeNull()
        ->and($account->calendar_push_verification_token)->toBeNull()
        ->and($account->calendar_push_expires_at)->toBeNull();
});

it('clears local push fields when the provider client cannot be created', function (): void {
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_push_channel_id' => 'channel-123',
        'calendar_push_resource_id' => 'resource-123',
        'calendar_push_verification_token' => 'secret',
        'calendar_push_expires_at' => now()->addDay(),
        'refresh_token' => null,
        'token_expires_at' => now()->subHour(),
    ]));

    resolve(StopCalendarPushChannelAction::class)->execute($account);

    $account->refresh();

    expect($account->calendar_push_channel_id)->toBeNull()
        ->and($account->calendar_push_verification_token)->toBeNull();
});
