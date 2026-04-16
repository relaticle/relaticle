<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailAccountsPage::class);

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

it('saves all four settings fields on editSettings', function (): void {
    livewire(EmailAccountsPage::class)
        ->callAction('editSettings', data: [
            'sync_inbox' => false,
            'sync_sent' => true,
            'contact_creation_mode' => ContactCreationMode::All->value,
            'auto_create_companies' => true,
        ], arguments: ['account_id' => $this->account->id]);

    expect($this->account->fresh())
        ->sync_inbox->toBeFalse()
        ->sync_sent->toBeTrue()
        ->contact_creation_mode->toBe(ContactCreationMode::All)
        ->auto_create_companies->toBeTrue();
});

it('does not update another user\'s account via editSettings', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
        'sync_inbox' => true,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('editSettings', data: [
            'sync_inbox' => false,
            'sync_sent' => false,
            'contact_creation_mode' => ContactCreationMode::None->value,
            'auto_create_companies' => false,
        ], arguments: ['account_id' => $otherAccount->id]);

    expect($otherAccount->fresh()->sync_inbox)->toBeTrue();
});

it('deletes the authenticated user\'s account on disconnect', function (): void {
    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $this->account->id]);

    $this->assertSoftDeleted('connected_accounts', [
        'id' => $this->account->id,
    ]);
});

it('does not delete another user\'s account on disconnect', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));

    livewire(EmailAccountsPage::class)
        ->callAction('disconnect', arguments: ['account_id' => $otherAccount->id]);

    $this->assertDatabaseHas('connected_accounts', [
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
