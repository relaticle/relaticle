<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountSettingsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage;
use Relaticle\EmailIntegration\Filament\Pages\UserEmailPrivacyPage;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;
use Relaticle\EmailIntegration\Jobs\InitialEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;

mutates(EmailAccountsPage::class, ConnectedAccount::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
});

it('links editSettings to the per-account settings page', function (): void {
    livewire(EmailAccountsPage::class)
        ->assertActionHasUrl(
            TestAction::make('editSettings')->arguments(['account_id' => $this->account->id]),
            EmailAccountSettingsPage::getUrl(['account' => $this->account->id]),
        );
});

it('deletes the authenticated user\'s account on disconnect', function (): void {
    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id]);

    $this->assertSoftDeleted(ConnectedAccount::class, [
        'id' => $this->account->id,
    ]);
});

it('deletes dependent signatures and refreshes the listing on disconnect', function (): void {
    $signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id])
        ->assertSet('connectedAccounts', fn ($accounts): bool => $accounts->isEmpty());

    $this->assertSoftDeleted(ConnectedAccount::class, ['id' => $this->account->id]);
    $this->assertDatabaseMissing(EmailSignature::class, ['id' => $signature->id]);
});

it('does not delete another user\'s account on disconnect', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));

    expect(fn () => livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $otherAccount->id]))
        ->toThrow(ModelNotFoundException::class);

    $this->assertNotSoftDeleted(ConnectedAccount::class, [
        'id' => $otherAccount->id,
    ]);
});

it('only loads the authenticated user\'s accounts in the current team on mount', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));

    $ids = livewire(EmailAccountsPage::class)
        ->get('connectedAccounts')
        ->pluck('id')
        ->all();

    expect($ids)->toContain($this->account->id)
        ->not->toContain($otherAccount->id);
});

it('promotes an account to default and demotes the previous default on setDefault', function (): void {
    $current = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->default()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('setDefault', arguments: ['account_id' => $this->account->id])
        ->assertNotified();

    expect($this->account->fresh()->is_default)->toBeTrue()
        ->and($current->fresh()->is_default)->toBeFalse();
});

it('hides setDefault for the account that is already default', function (): void {
    $default = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->default()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->assertActionHidden(TestAction::make('setDefault')->arguments(['account_id' => $default->id]))
        ->assertActionVisible(TestAction::make('setDefault')->arguments(['account_id' => $this->account->id]));
});

it('does not expose setDefault for another user\'s account', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->assertActionHidden(TestAction::make('setDefault')->arguments(['account_id' => $otherAccount->id]));

    expect($otherAccount->fresh()->is_default)->toBeFalse();
});

it('promotes the remaining account to default when the default is disconnected', function (): void {
    $default = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->default()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $default->id]);

    $this->assertSoftDeleted(ConnectedAccount::class, ['id' => $default->id]);
    expect($this->account->fresh()->is_default)->toBeTrue();
});

it('lists the default account first', function (): void {
    $default = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->default()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $ids = livewire(EmailAccountsPage::class)
        ->get('connectedAccounts')
        ->pluck('id')
        ->all();

    expect($ids[0])->toBe($default->id);
});

it('queues mailbox history import from the account menu', function (): void {
    Bus::fake();

    livewire(EmailAccountsPage::class)
        ->callAction('reimportHistory', arguments: ['account_id' => $this->account->id])
        ->assertNotified();

    Bus::assertDispatched(RelinkMailboxHistoryJob::class, fn (RelinkMailboxHistoryJob $job): bool => $job->connectedAccount->is($this->account));
    Bus::assertDispatched(InitialEmailSyncJob::class, fn (InitialEmailSyncJob $job): bool => $job->connectedAccount->is($this->account));
});

it('does not re-import another user\'s account', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));

    expect(fn () => livewire(EmailAccountsPage::class)
        ->callAction('reimportHistory', arguments: ['account_id' => $otherAccount->id]))
        ->toThrow(ModelNotFoundException::class);
});

it('shows mailbox capabilities on each connected account', function (): void {
    livewire(EmailAccountsPage::class)
        ->assertSee(__('filament/pages/email-accounts.capabilities.email'));
});

it('renders Connect Gmail and hides Connect Outlook for now', function (): void {
    livewire(EmailAccountsPage::class)
        ->assertActionExists('connectGmail')
        ->assertActionVisible('connectGmail')
        ->assertActionHidden('connectAzure');
});

it('shows only accounts and templates in the email settings cluster', function (): void {
    expect(EmailAccountsPage::shouldRegisterNavigation())->toBeTrue()
        ->and(EmailTemplateResource::shouldRegisterNavigation())->toBeTrue()
        ->and(EmailSignaturesPage::shouldRegisterNavigation())->toBeFalse()
        ->and(EmailAccessRequestsPage::shouldRegisterNavigation())->toBeFalse()
        ->and(UserEmailPrivacyPage::shouldRegisterNavigation())->toBeFalse();

    $this->get(EmailAccountsPage::getUrl(tenant: $this->team))
        ->assertSuccessful()
        ->assertSee(__('filament/pages/email-accounts.navigation_label'), false)
        ->assertSee(__('filament/resources/email-template.navigation_label'), false)
        ->assertDontSee(__('filament/pages/email-access-requests.navigation_label'), false)
        ->assertDontSee(__('filament/pages/email-privacy-settings.navigation_label'), false)
        ->assertDontSee(__('filament/pages/user-email-privacy.navigation_label'), false);
});
