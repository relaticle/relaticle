<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountSettingsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

mutates(EmailAccountSettingsPage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
});

it('loads the account form and existing blocklist entries on mount', function (): void {
    $this->user->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT->value]);

    EmailBlocklist::factory()->create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
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
        ->callAction('save', data: [
            'full_access_confirmation' => 'I understand',
        ])
        ->assertNotified();

    expect($this->account->fresh())
        ->sync_inbox->toBeFalse()
        ->sync_sent->toBeTrue()
        ->hourly_send_limit->toBe(25)
        ->daily_send_limit->toBe(100);

    expect($this->user->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::FULL);
});

it('requires confirmation when changing the account sharing tier to private', function (): void {
    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->fillForm([
            'default_email_sharing_tier' => EmailPrivacyTier::PRIVATE->value,
        ])
        ->mountAction('save')
        ->assertActionMounted('save');
});

it('rejects an incorrect full access confirmation phrase on account settings', function (): void {
    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->fillForm([
            'default_email_sharing_tier' => EmailPrivacyTier::FULL->value,
        ])
        ->callAction('save', data: [
            'full_access_confirmation' => 'not the phrase',
        ])
        ->assertHasActionErrors(['full_access_confirmation']);

    expect($this->user->fresh()->default_email_sharing_tier)->toBeNull();
});

it('saves account settings without confirmation when the sharing tier is unchanged', function (): void {
    $this->user->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT]);

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->fillForm([
            'sync_inbox' => false,
            'default_email_sharing_tier' => EmailPrivacyTier::SUBJECT->value,
        ])
        ->callAction('save')
        ->assertNotified();

    expect($this->account->fresh()->sync_inbox)->toBeFalse()
        ->and($this->user->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::SUBJECT);
});

it('clears a sharing override when selecting use workspace default even if the effective tier stays equal', function (): void {
    $this->workspace->update(['default_email_sharing_tier' => EmailPrivacyTier::FULL]);
    $this->user->update(['default_email_sharing_tier' => EmailPrivacyTier::FULL]);

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->set('data.default_email_sharing_tier', '')
        ->callAction('save')
        ->assertNotified();

    expect($this->user->fresh()->default_email_sharing_tier)->toBeNull();
});

it('persists an explicit sharing override when the effective tier matches the workspace default', function (): void {
    $metadataTeam = Workspace::factory()->create([
        'user_id' => $this->user->getKey(),
        'default_email_sharing_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->user->workspaces()->attach($metadataTeam, ['role' => 'admin']);
    $this->workspace->update(['default_email_sharing_tier' => EmailPrivacyTier::FULL]);

    $metadataAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $metadataTeam->getKey(),
        'user_id' => $this->user->id,
    ]));
    $metadataWorkspaceEmail = Email::factory()->create([
        'workspace_id' => $metadataTeam->getKey(),
        'user_id' => $this->user->id,
        'connected_account_id' => $metadataAccount->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'privacy_tier_customized' => false,
    ]);

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->set('data.default_email_sharing_tier', EmailPrivacyTier::FULL->value)
        ->callAction('save')
        ->assertNotified();

    expect($this->user->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($metadataWorkspaceEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
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

it('persists include subdomains from the blocklist modal onto new domain entries', function (): void {
    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->callAction('addBlocklist', data: [
            'blocklist_emails' => [],
            'blocklist_domains' => ['spammy.com'],
            'blocklist_include_subdomains' => true,
        ])
        ->assertNotified();

    $this->assertDatabaseHas(EmailBlocklist::class, [
        'connected_account_id' => $this->account->id,
        'type' => EmailBlocklistType::DOMAIN->value,
        'value' => 'spammy.com',
        'include_subdomains' => true,
    ]);
});

it('does not load another account\'s blocklist on this settings page', function (): void {
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    EmailBlocklist::factory()->create([
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
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
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'name' => 'Brand new',
        'is_default' => true,
    ]);
});

it('edits and deletes a signature from its card', function (): void {
    $signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'workspace_id' => $this->workspace->id,
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
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $signature = EmailSignature::withoutEvents(fn () => EmailSignature::factory()->create([
        'connected_account_id' => $otherAccount->id,
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->callAction('deleteSignature', arguments: ['signature_id' => $signature->id])
        ->assertNotFound();

    $this->assertDatabaseHas(EmailSignature::class, ['id' => $signature->id]);
});

it('does not show history import failure recovery on account settings', function (): void {
    $batch = resolve(MailboxHistoryImportService::class)->startBatch($this->account);

    $this->account->update([
        'sync_cursor' => 'history-done',
        'history_import_batch_id' => $batch->id,
    ]);

    DB::table('job_batches')->where('id', $batch->id)->update([
        'total_jobs' => 5,
        'pending_jobs' => 0,
        'failed_jobs' => 2,
        'failed_job_ids' => json_encode(['failed-1', 'failed-2']),
        'finished_at' => now()->getTimestamp(),
    ]);

    livewire(EmailAccountSettingsPage::class, ['account' => $this->account->id])
        ->assertDontSee(__('filament/pages/email-accounts.history_import_failure.badge'))
        ->assertDontSee(__('filament/pages/email-accounts.actions.retry_failed_import.label'));
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
    $otherUser = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $otherUser->id,
    ]));

    livewire(EmailAccountSettingsPage::class, ['account' => $otherAccount->id])
        ->assertNotFound();
});
