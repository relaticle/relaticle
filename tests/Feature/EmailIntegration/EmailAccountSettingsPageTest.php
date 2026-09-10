<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountSettingsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailSignature;

mutates(EmailAccountSettingsPage::class);

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

it('loads the account form and existing blocklist entries on mount', function (): void {
    $this->user->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT->value]);

    EmailBlocklist::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'type' => EmailBlocklistType::DOMAIN,
        'value' => 'spammy.com',
    ]);

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->assertSet('data.sync_inbox', $this->account->fresh()->sync_inbox)
        ->assertSet('data.default_email_sharing_tier', EmailPrivacyTier::SUBJECT->value)
        ->assertCount('blocklistEntries', 1);
});

it('does not expose workspace record creation controls on a personal account', function (): void {
    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->assertDontSee(__('filament/pages/email-privacy-settings.record_creation.modes.selective.label'))
        ->assertDontSee(__('filament/pages/email-privacy-settings.record_creation.companies.label'));
});

it('saves account settings and the sharing tier from the save action', function (): void {
    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->fillForm([
            'sync_inbox' => false,
            'sync_sent' => true,
            'hourly_send_limit' => 25,
            'daily_send_limit' => 100,
            'default_email_sharing_tier' => EmailPrivacyTier::FULL->value,
        ])
        ->callAction('save')
        ->assertNotified();

    expect($this->account->fresh())
        ->sync_inbox->toBeFalse()
        ->sync_sent->toBeTrue()
        ->hourly_send_limit->toBe(25)
        ->daily_send_limit->toBe(100);

    expect($this->user->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::FULL);
});

it('adds blocklist entries from the blocklist modal', function (): void {
    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->callAction('addBlocklist', data: [
            'blocklist_emails' => ['NOISY@Example.com'],
            'blocklist_domains' => ['Spammy.com'],
        ])
        ->assertNotified();

    $this->assertDatabaseHas(EmailBlocklist::class, [
        'connected_account_id' => $this->account->id,
        'type' => EmailBlocklistType::EMAIL->value,
        'value' => 'noisy@example.com',
    ]);

    $this->assertDatabaseHas(EmailBlocklist::class, [
        'connected_account_id' => $this->account->id,
        'type' => EmailBlocklistType::DOMAIN->value,
        'value' => 'spammy.com',
    ]);
});

it('does not load another account\'s blocklist on this settings page', function (): void {
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    EmailBlocklist::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $this->team->id,
        'connected_account_id' => $otherAccount->id,
        'type' => EmailBlocklistType::EMAIL,
        'value' => 'other@example.com',
    ]);

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->assertCount('blocklistEntries', 0);
});

it('creates a signature for this account from the signatures tab', function (): void {
    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->callAction('createSignature', data: [
            'name' => 'Brand new',
            'content_html' => '<p>Cheers</p>',
            'is_default' => true,
        ])
        ->assertNotified();

    $this->assertDatabaseHas(EmailSignature::class, [
        'connected_account_id' => $this->account->id,
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'name' => 'Brand new',
        'is_default' => true,
    ]);
});

it('edits and deletes a signature from its card', function (): void {
    $signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'name' => 'Old name',
        'is_default' => true,
    ]));

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->callAction('editSignature', data: [
            'name' => 'Renamed',
            'content_html' => '<p>Regards</p>',
            'is_default' => false,
        ], arguments: ['signature_id' => $signature->id])
        ->assertNotified();

    expect($signature->fresh())
        ->name->toBe('Renamed')
        ->is_default->toBeFalse();

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->callAction('deleteSignature', arguments: ['signature_id' => $signature->id]);

    $this->assertDatabaseMissing(EmailSignature::class, ['id' => $signature->id]);
});

it('does not touch another account\'s signature', function (): void {
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $otherAccount->id,
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    expect(fn () => livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->callAction('deleteSignature', arguments: ['signature_id' => $signature->id]))
        ->toThrow(ModelNotFoundException::class);

    $this->assertDatabaseHas(EmailSignature::class, ['id' => $signature->id]);
});

it('shows syncing percent while mailbox history is importing', function (): void {
    $this->account->update([
        'sync_cursor' => null,
        'initial_sync_imported' => 57,
        'initial_sync_estimated' => 100,
    ]);

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->assertSee(__('filament/pages/email-accounts.importing'))
        ->assertSee(__('filament/pages/email-accounts.importing_percent', ['percent' => 57]));
});

it('does not open the settings page for another user\'s account', function (): void {
    $otherUser = User::factory()->create(['current_team_id' => $this->team->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
    ]));

    expect(fn () => livewire(EmailAccountSettingsPage::class, ['account' => $otherAccount->id]))
        ->toThrow(ModelNotFoundException::class);
});
