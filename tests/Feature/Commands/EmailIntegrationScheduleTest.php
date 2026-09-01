<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Console\Commands\IncrementalCalendarSyncCommand;
use Relaticle\EmailIntegration\Console\Commands\IncrementalEmailSyncCommand;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(IncrementalEmailSyncCommand::class);
mutates(IncrementalCalendarSyncCommand::class);

it('registers outbox and sync schedules when email integration is active', function (): void {
    Feature::activate(EmailIntegration::class);
    $this->app->forgetInstance(Schedule::class);

    $this->artisan('schedule:list')
        ->expectsOutputToContain('email:dispatch-outbox')
        ->expectsOutputToContain('email:incremental-sync')
        ->expectsOutputToContain('calendar:incremental-sync')
        ->assertSuccessful();
});

it('runs email sync and outbox schedules on a single server without overlapping', function (): void {
    Feature::activate(EmailIntegration::class);
    $this->app->forgetInstance(Schedule::class);

    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(app(Schedule::class)->events())
        ->filter(function (Event $event): bool {
            $haystack = $event->getSummaryForDisplay().' '.($event->command ?? '');

            return str_contains($haystack, 'email:incremental-sync')
                || str_contains($haystack, 'calendar:incremental-sync')
                || str_contains($haystack, 'email:dispatch-outbox');
        });

    expect($events)->toHaveCount(3)
        ->and($events->every(fn (Event $event): bool => $event->onOneServer && $event->withoutOverlapping))->toBeTrue();
});

it('does not register outbox or sync schedules when email integration is inactive', function (): void {
    config()->set('relaticle.features.email_integration', false);
    Feature::flushCache();
    Feature::deactivate(EmailIntegration::class);
    $this->app->forgetInstance(Schedule::class);

    $this->artisan('schedule:list')
        ->doesntExpectOutputToContain('email:dispatch-outbox')
        ->doesntExpectOutputToContain('email:incremental-sync')
        ->doesntExpectOutputToContain('calendar:incremental-sync')
        ->assertSuccessful();
});

it('dispatches incremental email sync jobs only for active accounts with a cursor', function (): void {
    Bus::fake([IncrementalEmailSyncJob::class]);

    $user = User::factory()->withTeam()->create();
    $withCursor = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->id,
        'user_id' => $user->id,
        'sync_cursor' => 'cursor-1',
    ]));
    $withoutCursor = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->id,
        'user_id' => $user->id,
        'sync_cursor' => null,
    ]));

    $this->artisan('email:incremental-sync')->assertSuccessful();

    Bus::assertDispatched(fn (IncrementalEmailSyncJob $job): bool => $job->connectedAccount->is($withCursor));
    Bus::assertNotDispatched(fn (IncrementalEmailSyncJob $job): bool => $job->connectedAccount->is($withoutCursor));
});

it('dispatches incremental calendar sync jobs only for accounts with calendar enabled', function (): void {
    Bus::fake([IncrementalCalendarSyncJob::class]);

    $user = User::factory()->withTeam()->create();
    $withCalendar = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->id,
        'user_id' => $user->id,
        'capabilities' => ['calendar' => true],
    ]));
    $withoutCalendar = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->id,
        'user_id' => $user->id,
        'capabilities' => ['calendar' => false],
    ]));

    $this->artisan('calendar:incremental-sync')->assertSuccessful();

    Bus::assertDispatched(fn (IncrementalCalendarSyncJob $job): bool => $job->connectedAccount->is($withCalendar));
    Bus::assertNotDispatched(fn (IncrementalCalendarSyncJob $job): bool => $job->connectedAccount->is($withoutCalendar));
});
