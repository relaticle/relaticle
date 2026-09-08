<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Controllers\CalendarPushWebhookController;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Exceptions\CalendarPushChannelFailed;
use Relaticle\EmailIntegration\Jobs\EnsureCalendarPushChannelJob;
use Relaticle\EmailIntegration\Jobs\IncrementalCalendarSyncJob;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Livewire\MailboxImportStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\MailboxSyncTracker;

mutates(
    CalendarPushWebhookController::class,
    EnsureCalendarPushChannelJob::class,
    MailboxImportStatus::class,
);

it('accepts microsoft subscription validation tokens', function (): void {
    $this->post(route('calendar-push.webhook', ['provider' => EmailProvider::AZURE->value]).'?validationToken=abc123')
        ->assertOk()
        ->assertSee('abc123');
});

it('dispatches calendar sync when google sends a change notification', function (): void {
    Bus::fake([IncrementalCalendarSyncJob::class]);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'provider' => EmailProvider::GMAIL,
        'calendar_push_channel_id' => 'channel-123',
        'calendar_push_verification_token' => 'secret-token',
    ]));

    $this->post(route('calendar-push.webhook', ['provider' => EmailProvider::GMAIL->value]), [], [
        'X-Goog-Channel-ID' => 'channel-123',
        'X-Goog-Channel-Token' => 'secret-token',
        'X-Goog-Resource-State' => 'exists',
    ])->assertOk();

    Bus::assertDispatched(fn (IncrementalCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('rejects google notifications with the wrong verification token', function (): void {
    Bus::fake([IncrementalCalendarSyncJob::class]);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'provider' => EmailProvider::GMAIL,
        'calendar_push_channel_id' => 'channel-123',
        'calendar_push_verification_token' => 'secret-token',
    ]));

    $this->post(route('calendar-push.webhook', ['provider' => EmailProvider::GMAIL->value]), [], [
        'X-Goog-Channel-ID' => 'channel-123',
        'X-Goog-Channel-Token' => 'wrong-token',
        'X-Goog-Resource-State' => 'exists',
    ])->assertForbidden();

    Bus::assertNothingDispatched();
});

it('shows calendar-only sync progress on the dashboard', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($user->currentTeam);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'sync_cursor' => 'done',
        'calendar_sync_cursor' => 'done',
        'capabilities' => ['email' => true, 'calendar' => true],
    ]));

    MailboxSyncTracker::markCalendarStarted($account);

    Livewire::test(MailboxImportStatus::class, ['placement' => 'home'])
        ->assertSee(__('filament/pages/email-accounts.importing_calendar'))
        ->assertSee(__('filament/pages/email-accounts.sync_status.title_syncing'));
});

it('dispatches calendar sync when microsoft sends a valid notification', function (): void {
    Bus::fake([IncrementalCalendarSyncJob::class]);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->azure()->create([
        'calendar_push_channel_id' => 'sub-123',
        'calendar_push_verification_token' => 'secret-token',
    ]));

    $this->postJson(route('calendar-push.webhook', ['provider' => EmailProvider::AZURE->value]), [
        'value' => [[
            'subscriptionId' => 'sub-123',
            'clientState' => 'secret-token',
        ]],
    ])->assertAccepted();

    Bus::assertDispatched(fn (IncrementalCalendarSyncJob $job): bool => $job->connectedAccount->is($account));
});

it('ignores microsoft notifications with a forged client state', function (): void {
    Bus::fake([IncrementalCalendarSyncJob::class]);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->azure()->create([
        'calendar_push_channel_id' => 'sub-123',
        'calendar_push_verification_token' => 'secret-token',
    ]));

    $this->postJson(route('calendar-push.webhook', ['provider' => EmailProvider::AZURE->value]), [
        'value' => [[
            'subscriptionId' => 'sub-123',
            'clientState' => 'forged-token',
        ]],
    ])->assertAccepted();

    Bus::assertNothingDispatched();
});

it('marks email sync as started when the scheduled command dispatches incremental sync', function (): void {
    Bus::fake([IncrementalEmailSyncJob::class]);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'sync_cursor' => 'cursor-1',
    ]));

    $this->artisan('email:incremental-sync')->assertSuccessful();

    expect(MailboxSyncTracker::isEmailSyncing($account))->toBeTrue();
});

it('retries microsoft push renewal failures instead of replacing the subscription', function (): void {
    config()->set('app.url', 'https://app.relaticle.com');

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->azure()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'cursor',
        'calendar_push_channel_id' => 'sub-123',
        'calendar_push_expires_at' => now()->addHours(6),
    ]));

    Http::fake([
        'https://graph.microsoft.com/v1.0/subscriptions/sub-123' => Http::response('', 503),
    ]);

    $job = new EnsureCalendarPushChannelJob($account);

    expect(fn () => $job->handle(app(CalendarServiceFactoryInterface::class)))
        ->toThrow(CalendarPushChannelFailed::class);

    expect($account->fresh()?->calendar_push_channel_id)->toBe('sub-123');
});

it('records push channel renewal failures on the connected account', function (): void {
    config()->set('app.url', 'https://app.relaticle.com');

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->azure()->create([
        'capabilities' => ['email' => true, 'calendar' => true],
        'calendar_sync_cursor' => 'cursor',
        'calendar_push_channel_id' => 'sub-123',
        'calendar_push_expires_at' => now()->addHours(6),
        'status' => EmailAccountStatus::ACTIVE,
    ]));

    Http::fake([
        'https://graph.microsoft.com/v1.0/subscriptions/sub-123' => Http::response('', 503),
    ]);

    $job = new EnsureCalendarPushChannelJob($account);

    try {
        $job->handle(app(CalendarServiceFactoryInterface::class));
    } catch (CalendarPushChannelFailed) {
        // expected during handle when retries are exhausted in production
    }

    $job->failed(new CalendarPushChannelFailed('renewal failed'));

    expect($account->fresh()?->last_error)->toContain('Calendar push notifications could not be renewed');
});

it('accepts malformed microsoft webhook payloads without dispatching sync', function (): void {
    Bus::fake([IncrementalCalendarSyncJob::class]);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->azure()->create([
        'calendar_push_channel_id' => 'sub-123',
        'calendar_push_verification_token' => 'secret-token',
    ]));

    $this->postJson(route('calendar-push.webhook', ['provider' => EmailProvider::AZURE->value]), [
        'value' => 'not-an-array',
    ])->assertAccepted();

    $this->postJson(route('calendar-push.webhook', ['provider' => EmailProvider::AZURE->value]), [
        'value' => [
            'subscriptionId' => 'sub-123',
            'clientState' => 'secret-token',
        ],
    ])->assertAccepted();

    $this->postJson(route('calendar-push.webhook', ['provider' => EmailProvider::AZURE->value]), [
        'value' => [
            ['subscriptionId' => 123, 'clientState' => 'secret-token'],
        ],
    ])->assertAccepted();

    Bus::assertNothingDispatched();
});
