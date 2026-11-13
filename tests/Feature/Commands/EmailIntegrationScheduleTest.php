<?php

declare(strict_types=1);

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Features\EmailIntegration;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Console\Commands\IncrementalCalendarSyncCommand;
use Relaticle\EmailIntegration\Console\Commands\IncrementalEmailSyncCommand;
use Relaticle\EmailIntegration\Console\Commands\RenewCalendarPushChannelsCommand;
use Relaticle\EmailIntegration\Jobs\EnsureCalendarPushChannelJob;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Tests\Helpers\BillingStatusFixture;

mutates(IncrementalEmailSyncCommand::class);
mutates(IncrementalCalendarSyncCommand::class);
mutates(RenewCalendarPushChannelsCommand::class);
mutates(ConnectedAccount::class);
mutates(BillingStatus::class);

it('registers outbox and sync schedules when email integration is active', function (): void {
    Feature::activate(EmailIntegration::class);
    $this->app->forgetInstance(Schedule::class);

    $this->artisan('schedule:list')
        ->expectsOutputToContain('email:dispatch-outbox')
        ->expectsOutputToContain('email:incremental-sync')
        ->expectsOutputToContain('calendar:incremental-sync')
        ->expectsOutputToContain('calendar:renew-push-channels')
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
                || str_contains($haystack, 'calendar:renew-push-channels')
                || str_contains($haystack, 'email:dispatch-outbox');
        });

    expect($events)->toHaveCount(4)
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
        ->doesntExpectOutputToContain('calendar:renew-push-channels')
        ->assertSuccessful();
});

it('dispatches incremental email sync jobs only for active accounts with a cursor', function (): void {
    Bus::fake([IncrementalEmailSyncJob::class]);

    $user = User::factory()->withWorkspace()->create();
    $withCursor = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'user_id' => $user->id,
        'sync_cursor' => 'cursor-1',
    ]));
    $withoutCursor = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'user_id' => $user->id,
        'sync_cursor' => null,
    ]));

    $this->artisan('email:incremental-sync')->assertSuccessful();

    Bus::assertDispatched(fn (IncrementalEmailSyncJob $job): bool => $job->connectedAccount->is($withCursor));
    Bus::assertNotDispatched(fn (IncrementalEmailSyncJob $job): bool => $job->connectedAccount->is($withoutCursor));
});

it('dispatches incremental calendar sync jobs only for accounts with calendar enabled', function (): void {
    Bus::fake([IncrementalCalendarSyncJob::class]);

    $user = User::factory()->withWorkspace()->create();
    $withCalendar = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'user_id' => $user->id,
        'capabilities' => ['calendar' => true],
    ]));
    $withoutCalendar = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $user->currentWorkspace->id,
        'user_id' => $user->id,
        'capabilities' => ['calendar' => false],
    ]));

    $this->artisan('calendar:incremental-sync')->assertSuccessful();

    Bus::assertDispatched(fn (IncrementalCalendarSyncJob $job): bool => $job->connectedAccount->is($withCalendar));
    Bus::assertNotDispatched(fn (IncrementalCalendarSyncJob $job): bool => $job->connectedAccount->is($withoutCalendar));
});

it('syncs mailboxes only in workspaces whose billing status grants hosted access', function (): void {
    withHostedBilling();
    Bus::fake([IncrementalEmailSyncJob::class, IncrementalCalendarSyncJob::class]);

    $accounts = collect(BillingStatusFixture::arrangements())->map(function (array $arrangement): array {
        [$arrange, $status] = $arrangement;
        $workspace = BillingStatusFixture::workspace();
        $arrange($workspace);

        $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $workspace->user_id,
            'sync_cursor' => 'cursor',
            'capabilities' => ['email' => true, 'calendar' => true],
        ]));

        return ['account' => $account, 'syncs' => $status->grantsAccess()];
    });

    $this->artisan('email:incremental-sync')->assertSuccessful();
    $this->artisan('calendar:incremental-sync')->assertSuccessful();

    $syncedEmail = Bus::dispatched(IncrementalEmailSyncJob::class)->map(fn (IncrementalEmailSyncJob $job): string => $job->connectedAccount->getKey());
    $syncedCalendar = Bus::dispatched(IncrementalCalendarSyncJob::class)->map(fn (IncrementalCalendarSyncJob $job): string => $job->connectedAccount->getKey());
    $expected = $accounts->filter(fn (array $entry): bool => $entry['syncs'])->map(fn (array $entry): string => $entry['account']->getKey());

    expect($accounts->contains(fn (array $entry): bool => ! $entry['syncs']))->toBeTrue()
        ->and($syncedEmail->all())->toEqualCanonicalizing($expected->values()->all())
        ->and($syncedCalendar->all())->toEqualCanonicalizing($expected->values()->all());
});

it('resumes a paused workspace mailbox sync once the workspace subscribes', function (): void {
    withHostedBilling();
    Bus::fake([IncrementalEmailSyncJob::class]);

    $workspace = BillingStatusFixture::workspace();
    $workspace->forceFill(['pro_trial_used_at' => now()->subDays(15)])->save();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $workspace->user_id,
        'sync_cursor' => 'cursor',
    ]));

    $this->artisan('email:incremental-sync')->assertSuccessful();

    Bus::assertNotDispatched(IncrementalEmailSyncJob::class);

    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey()]);

    $this->artisan('email:incremental-sync')->assertSuccessful();

    Bus::assertDispatched(fn (IncrementalEmailSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('keeps renewing calendar push channels only in workspaces with hosted access', function (): void {
    withHostedBilling();
    config()->set('app.url', 'https://app.relaticle.com');
    Bus::fake([EnsureCalendarPushChannelJob::class]);

    $mailboxIn = fn (Workspace $workspace): ConnectedAccount => ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $workspace->user_id,
        'capabilities' => ['calendar' => true],
        'calendar_sync_cursor' => 'cursor',
        'calendar_push_expires_at' => now()->addHours(2),
    ]));

    $trialing = BillingStatusFixture::workspace();
    $trialing->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save();
    $kept = $mailboxIn($trialing);
    $paused = $mailboxIn(BillingStatusFixture::workspace());

    $this->artisan('calendar:renew-push-channels')->assertSuccessful();

    Bus::assertDispatched(fn (EnsureCalendarPushChannelJob $job): bool => $job->connectedAccount->is($kept));
    Bus::assertNotDispatched(fn (EnsureCalendarPushChannelJob $job): bool => $job->connectedAccount->is($paused));
});
