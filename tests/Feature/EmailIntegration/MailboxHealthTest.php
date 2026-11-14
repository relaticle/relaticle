<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Cashier\Subscription;
use Relaticle\EmailIntegration\Actions\ConnectAccountAction;
use Relaticle\EmailIntegration\Actions\DisconnectConnectedAccountAction;
use Relaticle\EmailIntegration\Actions\RecordMailboxHealth;
use Relaticle\EmailIntegration\Console\Commands\CheckMailboxHealthCommand;
use Relaticle\EmailIntegration\Data\ConnectAccountData;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Enums\MailboxHealth;
use Relaticle\EmailIntegration\Enums\SyncErrorReason;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHealthNotification;
use Tests\Helpers\BillingStatusFixture;

mutates(CheckMailboxHealthCommand::class, RecordMailboxHealth::class, ConnectedAccount::class, MailboxHealth::class, MailboxHealthNotification::class);

beforeEach(function (): void {
    $this->travelTo(now()->startOfMinute());
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
});

function healthMailbox(User $owner, array $attributes = []): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $owner->current_workspace_id, 'user_id' => $owner->id,
        'sync_cursor' => 'cursor', 'last_synced_at' => now()->subMinutes(5), ...$attributes,
    ]));
}

it('marks an active mailbox stale after a day without a successful sync, and not before', function (): void {
    $quiet = healthMailbox($this->owner, ['last_synced_at' => now()->subHours(23), 'email_address' => 'quiet@acme.test']);
    $stalled = healthMailbox($this->owner, ['last_synced_at' => now()->subHours(25)]);
    $importing = healthMailbox($this->owner, ['sync_cursor' => null, 'last_synced_at' => null, 'email_address' => 'new@acme.test']);

    $this->artisan('email:check-mailbox-health')->assertSuccessful();

    expect($quiet->fresh()->health)->toBe(MailboxHealth::Healthy)
        ->and($stalled->fresh()->health)->toBe(MailboxHealth::Stale)
        ->and($stalled->fresh()->health_reason)->toBe('no_sync_24h')
        ->and($stalled->fresh()->health_changed_at->equalTo(now()))->toBeTrue()
        ->and($importing->fresh()->health)->toBe(MailboxHealth::Healthy);
});

it('keeps the time a mailbox turned unhealthy across later checks', function (): void {
    $stalled = healthMailbox($this->owner, ['last_synced_at' => now()->subHours(25)]);
    $this->artisan('email:check-mailbox-health');
    $turnedStaleAt = $stalled->fresh()->health_changed_at;

    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');

    expect($stalled->fresh()->health_changed_at->equalTo($turnedStaleAt))->toBeTrue();
});

it('takes reconnect and error states from the mailbox status', function (): void {
    $reauth = healthMailbox($this->owner, ['status' => EmailAccountStatus::REAUTH_REQUIRED]);
    $error = healthMailbox($this->owner, ['status' => EmailAccountStatus::ERROR, 'last_error' => SyncErrorReason::GoogleMailDisabled->value, 'email_address' => 'b@acme.test']);

    $this->artisan('email:check-mailbox-health')->assertSuccessful();

    expect($reauth->fresh()->health)->toBe(MailboxHealth::ReauthRequired)
        ->and($error->fresh()->health)->toBe(MailboxHealth::Error)
        ->and($error->fresh()->health_reason)->toBe(SyncErrorReason::GoogleMailDisabled->value);
});

it('reports a mailbox in a workspace without paid access as paused, never stale', function (): void {
    withHostedBilling();
    $workspace = BillingStatusFixture::workspace();
    $paused = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $workspace->user_id,
        'sync_cursor' => 'cursor', 'last_synced_at' => now()->subDays(15),
    ]));
    $trialing = BillingStatusFixture::workspace();
    $trialing->forceFill(['trial_ends_at' => now()->addDays(5)])->save();
    $paying = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $trialing->id, 'user_id' => $trialing->user_id,
        'sync_cursor' => 'cursor', 'last_synced_at' => now()->subMinutes(5),
    ]));

    $this->artisan('email:check-mailbox-health')->assertSuccessful();

    expect($paused->fresh()->health)->toBe(MailboxHealth::PausedBilling)
        ->and(ConnectedAccount::query()->stale()->whereKey($paused->id)->exists())->toBeFalse()
        ->and($paying->fresh()->health)->toBe(MailboxHealth::Healthy);
});

it('returns a reconnected mailbox to healthy at once', function (): void {
    $account = healthMailbox($this->owner, ['status' => EmailAccountStatus::REAUTH_REQUIRED, 'last_error' => SyncErrorReason::GoogleMailDisabled->value]);
    $this->artisan('email:check-mailbox-health');
    $account->forceFill(['health_changed_at' => now()->subDay(), 'health_notified_at' => now()->subDay()])->save();
    Bus::fake();

    resolve(ConnectAccountAction::class)->execute(new ConnectAccountData(
        userId: (string) $this->owner->id, workspaceId: (string) $this->workspace->id,
        provider: $account->provider->value, emailAddress: $account->email_address,
        displayName: $account->display_name, providerAccountId: $account->provider_account_id,
        accessToken: 'new-access', refreshToken: 'new-refresh', tokenExpiresAt: now()->addHour(),
        hasCalendar: false, hasSend: true,
    ));

    expect($account->fresh()->health)->toBe(MailboxHealth::Healthy)
        ->and($account->fresh()->health_reason)->toBeNull()
        ->and($account->fresh()->health_changed_at->equalTo(now()))->toBeTrue()
        ->and($account->fresh()->health_notified_at)->toBeNull();
});

it('drops a disconnected mailbox from the watchdog and the attention list', function (): void {
    $account = healthMailbox($this->owner, ['last_synced_at' => now()->subHours(25)]);
    $this->artisan('email:check-mailbox-health');
    Http::fake();
    Bus::fake();

    resolve(DisconnectConnectedAccountAction::class)->execute($account->fresh());
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health')->assertSuccessful();

    expect(ConnectedAccount::query()->unhealthy()->whereKey($account->id)->exists())->toBeFalse()
        ->and(ConnectedAccount::query()->needingAttention()->whereKey($account->id)->exists())->toBeFalse()
        ->and(ConnectedAccount::withTrashed()->find($account->id)->health_changed_at->equalTo(now()->subMinutes(15)))->toBeTrue();
});

it('tells workspace admins only after the mailbox has been unhealthy for three days', function (): void {
    Notification::fake();
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    healthMailbox($member, ['status' => EmailAccountStatus::REAUTH_REQUIRED]);

    $this->artisan('email:check-mailbox-health');
    $this->travel(71)->hours();
    $this->artisan('email:check-mailbox-health');

    Notification::assertNotSentTo($admin, MailboxHealthNotification::class);

    $this->travel(2)->hours();
    $this->artisan('email:check-mailbox-health');
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');

    Notification::assertSentToTimes($admin, MailboxHealthNotification::class, 1);
    Notification::assertSentToTimes($this->owner, MailboxHealthNotification::class, 1);
    Notification::assertSentToTimes($member, MailboxHealthNotification::class, 1);
});

it('tells admins at once when billing pauses a mailbox', function (): void {
    Notification::fake();
    withHostedBilling();
    $workspace = BillingStatusFixture::workspace();
    $member = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $member->id, 'sync_cursor' => 'cursor',
    ]));

    $this->artisan('email:check-mailbox-health');

    Notification::assertSentTo($member, MailboxHealthNotification::class, fn (MailboxHealthNotification $notice): bool => $notice->facts()['for_admins'] === false);
    Notification::assertSentTo($workspace->owner, MailboxHealthNotification::class, fn (MailboxHealthNotification $notice): bool => $notice->facts()['for_admins'] === true);
});

it('tells the owner again after a recovery and a new failure', function (): void {
    Notification::fake();
    $account = healthMailbox($this->owner, ['status' => EmailAccountStatus::ERROR]);
    $this->artisan('email:check-mailbox-health');

    $account->update(['status' => EmailAccountStatus::ACTIVE, 'last_synced_at' => now()]);
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');

    expect($account->fresh()->health_notified_at)->toBeNull();

    $account->update(['status' => EmailAccountStatus::ERROR]);
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');

    Notification::assertSentToTimes($this->owner, MailboxHealthNotification::class, 2);
});

it('links the owner to the reconnect page and gives admins no reconnect link', function (): void {
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    healthMailbox($this->owner, ['status' => EmailAccountStatus::REAUTH_REQUIRED, 'email_address' => 'sales@acme.test']);

    $this->artisan('email:check-mailbox-health');
    $this->travel(73)->hours();
    $this->artisan('email:check-mailbox-health');

    $ownerNotice = MailboxHealthNotification::present($this->owner->notifications()->sole());
    $adminNotice = MailboxHealthNotification::present($admin->notifications()->sole());

    expect($ownerNotice->getBody())->toContain('sales@acme.test')
        ->and($ownerNotice->getActions()[0]->getUrl())->toBe(EmailAccountsPage::getUrl(panel: 'app', tenant: $this->workspace))
        ->and($adminNotice->getBody())->toContain('sales@acme.test')->toContain($this->owner->name)
        ->and($adminNotice->getActions())->toBe([]);
});

it('gives a mailbox whose workspace regains access a full day to sync before calling it stale', function (): void {
    Notification::fake();
    withHostedBilling();
    $workspace = BillingStatusFixture::workspace();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $workspace->user_id,
        'sync_cursor' => 'cursor', 'last_synced_at' => now()->subDays(15),
    ]));
    $this->artisan('email:check-mailbox-health');

    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey()]);
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');

    expect($account->fresh()->health)->toBe(MailboxHealth::Healthy);
    Notification::assertSentToTimes($workspace->owner, MailboxHealthNotification::class, 1);

    $this->travel(24)->hours();
    $this->artisan('email:check-mailbox-health');

    expect($account->fresh()->health)->toBe(MailboxHealth::Stale);
    Notification::assertSentToTimes($workspace->owner, MailboxHealthNotification::class, 2);
});

function reconnect(User $owner, ConnectedAccount $account): void
{
    resolve(ConnectAccountAction::class)->execute(new ConnectAccountData(
        userId: (string) $owner->id, workspaceId: (string) $account->workspace_id,
        provider: $account->provider->value, emailAddress: $account->email_address,
        displayName: $account->display_name, providerAccountId: $account->provider_account_id,
        accessToken: 'new-access', refreshToken: 'new-refresh', tokenExpiresAt: now()->addHour(),
        hasCalendar: false, hasSend: true,
    ));
}

it('records a mailbox that was already failing before health tracking without telling anyone', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $account = healthMailbox($this->owner, ['status' => EmailAccountStatus::REAUTH_REQUIRED, 'health_changed_at' => null]);

    $this->artisan('email:check-mailbox-health');

    expect($account->fresh()->health)->toBe(MailboxHealth::ReauthRequired)
        ->and($account->fresh()->health_changed_at)->toBeNull()
        ->and($account->fresh()->health_notified_at->equalTo(now()))->toBeTrue();

    $this->travel(73)->hours();
    $this->artisan('email:check-mailbox-health');

    Notification::assertNothingSent();

    $account->update(['status' => EmailAccountStatus::ACTIVE, 'last_synced_at' => now()]);
    $this->artisan('email:check-mailbox-health');
    $account->update(['status' => EmailAccountStatus::ERROR]);
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');

    Notification::assertSentToTimes($this->owner, MailboxHealthNotification::class, 1);
});

it('tells the owner when a mailbox connected after health tracking fails', function (): void {
    Notification::fake();
    Bus::fake();
    resolve(ConnectAccountAction::class)->execute(new ConnectAccountData(
        userId: (string) $this->owner->id, workspaceId: (string) $this->workspace->id,
        provider: EmailProvider::GMAIL->value, emailAddress: 'fresh@acme.test', displayName: 'Fresh',
        providerAccountId: 'fresh-id', accessToken: 'access', refreshToken: 'refresh', tokenExpiresAt: now()->addHour(),
        hasCalendar: false, hasSend: true,
    ));
    $account = ConnectedAccount::query()->where('email_address', 'fresh@acme.test')->sole();

    expect($account->health_changed_at?->equalTo(now()))->toBeTrue();

    $account->update(['status' => EmailAccountStatus::ERROR]);
    $this->artisan('email:check-mailbox-health');

    Notification::assertSentToTimes($this->owner, MailboxHealthNotification::class, 1);
});

it('reads the mailbox status as it stands when the check reaches it', function (): void {
    Notification::fake();
    $first = healthMailbox($this->owner, ['status' => EmailAccountStatus::ERROR, 'email_address' => 'a@acme.test']);
    $second = healthMailbox($this->owner, ['status' => EmailAccountStatus::ERROR, 'email_address' => 'b@acme.test']);
    $recovered = null;
    ConnectedAccount::saved(function (ConnectedAccount $saved) use ($first, $second, &$recovered): void {
        if ($recovered !== null) {
            return;
        }

        $recovered = $saved->is($first) ? $second : $first;
        ConnectedAccount::query()->whereKey($recovered->id)->update(['status' => EmailAccountStatus::ACTIVE->value]);
    });

    $this->artisan('email:check-mailbox-health');

    expect($recovered->fresh()->health)->toBe(MailboxHealth::Healthy);
    Notification::assertSentToTimes($this->owner, MailboxHealthNotification::class, 1);
});

it('does not repeat the billing notices when the owner reconnects during a pause', function (): void {
    Notification::fake();
    Bus::fake();
    withHostedBilling();
    $workspace = BillingStatusFixture::workspace();
    $admin = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $workspace->user_id, 'sync_cursor' => 'cursor',
    ]));
    $this->artisan('email:check-mailbox-health');

    reconnect($workspace->owner, $account);
    $this->travel(15)->minutes();
    $this->artisan('email:check-mailbox-health');

    expect($account->fresh()->health)->toBe(MailboxHealth::PausedBilling);
    Notification::assertSentToTimes($workspace->owner, MailboxHealthNotification::class, 1);
    Notification::assertSentToTimes($admin, MailboxHealthNotification::class, 1);
});

it('keeps the health it was sent for when the mailbox recovers before delivery', function (): void {
    $account = healthMailbox($this->owner, ['health' => MailboxHealth::Stale, 'email_address' => 'stale@acme.test']);
    $notice = new MailboxHealthNotification($account, MailboxHealth::Stale, forAdmins: false);
    ConnectedAccount::query()->whereKey($account->id)->update(['health' => MailboxHealth::Healthy->value]);

    $this->owner->notify($notice);

    expect(MailboxHealthNotification::present($this->owner->notifications()->sole())->getTitle())
        ->toBe(__('filament/notifications/mailbox-health.stale.owner.title'));
});

it('writes names into the mail without escaping them twice', function (): void {
    $this->owner->forceFill(['name' => "Liam O'Brien"])->save();
    $account = healthMailbox($this->owner, ['health' => MailboxHealth::ReauthRequired]);
    $admin = User::factory()->create(['name' => "Aoife O'Neill"]);

    $mail = (new MailboxHealthNotification($account->fresh(), MailboxHealth::ReauthRequired, forAdmins: true))->toMail($admin);

    expect(implode(' ', $mail->introLines))->toContain("Liam O'Brien")->not->toContain('&#039;')
        ->and($mail->greeting)->toContain("Aoife O'Neill");
});

it('renders owner and admin copy for every unhealthy state', function (MailboxHealth $health, bool $forAdmins): void {
    $account = healthMailbox($this->owner, ['health' => $health, 'email_address' => 'copy@acme.test']);

    $this->owner->notify(new MailboxHealthNotification($account, $health, $forAdmins));
    $notice = MailboxHealthNotification::present($this->owner->notifications()->sole());

    expect($notice->getTitle())->not->toStartWith('filament/')
        ->and($notice->getBody())->toContain('copy@acme.test')->not->toContain('filament/');
})->with([
    MailboxHealth::Stale, MailboxHealth::Error, MailboxHealth::ReauthRequired, MailboxHealth::PausedBilling,
])->with(['owner' => false, 'admins' => true]);

it('tells the owner about a billing pause after reconnecting a mailbox from before health tracking', function (): void {
    Notification::fake();
    Bus::fake();
    withHostedBilling();
    $workspace = BillingStatusFixture::workspace();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id, 'user_id' => $workspace->user_id, 'sync_cursor' => 'cursor',
        'status' => EmailAccountStatus::REAUTH_REQUIRED, 'health_changed_at' => null,
    ]));
    $account->delete();

    reconnect($workspace->owner, $account);

    expect($account->fresh()->health)->toBe(MailboxHealth::PausedBilling)
        ->and($account->fresh()->health_changed_at)->not->toBeNull();

    $this->artisan('email:check-mailbox-health');

    Notification::assertSentToTimes($workspace->owner, MailboxHealthNotification::class, 1);
});

it('sends no late admin notice for a mailbox reconnected while the check runs', function (): void {
    Notification::fake();
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $account = healthMailbox($member, ['status' => EmailAccountStatus::REAUTH_REQUIRED]);
    $this->artisan('email:check-mailbox-health');
    $this->travel(73)->hours();
    $reconnected = false;
    ConnectedAccount::retrieved(function (ConnectedAccount $retrieved) use ($account, &$reconnected): void {
        if ($reconnected || ! $retrieved->is($account)) {
            return;
        }

        $reconnected = true;
        ConnectedAccount::query()->whereKey($account->id)->update([
            'status' => EmailAccountStatus::ACTIVE->value, 'health' => MailboxHealth::Healthy->value,
            'health_changed_at' => now(), 'health_notified_at' => null, 'last_synced_at' => now(),
        ]);
    });

    $this->artisan('email:check-mailbox-health');

    expect($reconnected)->toBeTrue();
    Notification::assertNotSentTo($this->owner, MailboxHealthNotification::class);
});
