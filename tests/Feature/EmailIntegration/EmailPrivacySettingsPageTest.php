<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\UpdateWorkspaceContactCreationSettingsAction;
use Relaticle\EmailIntegration\Actions\UpdateWorkspaceEmailVisibilityAction;
use Relaticle\EmailIntegration\Actions\UpdateWorkspaceEmailVisibilityEntryAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;
use Relaticle\EmailIntegration\Livewire\EmailVisibilityTable;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(
    EmailPrivacySettingsPage::class,
    EmailVisibilityTable::class,
    UpdateWorkspaceEmailVisibilityAction::class,
    UpdateWorkspaceEmailVisibilityEntryAction::class,
    UpdateWorkspaceContactCreationSettingsAction::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

it('opens the tab named in the url and falls back to visibility for an unknown tab', function (string $requested, string $expected): void {
    Livewire::withQueryParams(['tab' => $requested])
        ->test(EmailPrivacySettingsPage::class)
        ->assertSet('tab', $expected);
})->with([
    'retired sharing tab' => ['sharing', 'visibility'],
    'record creation' => ['record_creation', 'record_creation'],
    'unknown' => ['outbox', 'visibility'],
]);

it('shows enforcement level explanations in the row picker', function (): void {
    WorkspaceEmailBlocklist::factory()->protected()->email('legal@acme.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailVisibilityTable::class)
        ->assertSee(EmailVisibilityEnforcement::Protected->getLabel())
        ->assertSee(EmailVisibilityEnforcement::Protected->getDescription())
        ->assertSee(EmailVisibilityEnforcement::Blocked->getLabel())
        ->assertSee(EmailVisibilityEnforcement::Blocked->getDescription());
});

it('creates protected WorkspaceEmailBlocklist rows from the add contacts modal', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'visibility')
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => ['legal@acme.com', 'hr@acme.com'],
            'visibility_domains' => [],
        ])
        ->assertNotified();

    expect(WorkspaceEmailBlocklist::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('enforcement_level', EmailVisibilityEnforcement::Protected->value)
        ->where('type', 'email')
        ->pluck('value')
        ->sort()
        ->values()
        ->all()
    )->toBe(['hr@acme.com', 'legal@acme.com']);
});

it('defaults new visibility entries to protected and allows changing enforcement from the table', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'visibility')
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => [],
            'visibility_domains' => ['spam.com'],
        ])
        ->assertNotified();

    $entry = WorkspaceEmailBlocklist::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('type', 'domain')
        ->where('value', 'spam.com')
        ->firstOrFail();

    expect($entry->enforcement_level)->toBe(EmailVisibilityEnforcement::Protected);

    livewire(EmailVisibilityTable::class)
        ->call('updateEnforcement', (string) $entry->id, EmailVisibilityEnforcement::Blocked->value)
        ->assertNotified();

    expect($entry->fresh()->enforcement_level)->toBe(EmailVisibilityEnforcement::Blocked);
});

it('persists include subdomains from the add contacts modal onto new domain entries', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'visibility')
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => [],
            'visibility_domains' => ['acme.com'],
            'visibility_include_subdomains' => true,
        ])
        ->assertNotified();

    $entry = WorkspaceEmailBlocklist::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('type', 'domain')
        ->where('value', 'acme.com')
        ->firstOrFail();

    expect($entry->include_subdomains)->toBeTrue();
});

it('normalizes domain urls when adding visibility entries', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'visibility')
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => [],
            'visibility_domains' => ['https://mail.outskill.com', 'outskill.com'],
        ])
        ->assertNotified();

    expect(WorkspaceEmailBlocklist::query()
        ->where('workspace_id', $this->workspace->id)
        ->where('type', 'domain')
        ->orderBy('value')
        ->pluck('value')
        ->all()
    )->toBe(['mail.outskill.com', 'outskill.com']);

    livewire(EmailVisibilityTable::class)
        ->assertSee('mail.outskill.com')
        ->assertSee('outskill.com')
        ->assertSee(EmailVisibilityEnforcement::Protected->getLabel());
});

it('shows system default visibility rows on the visibility tab', function (): void {
    $this->user->update(['email' => 'owner@thefireflytech.com']);

    livewire(EmailPrivacySettingsPage::class)
        ->assertSee(__('filament/pages/email-privacy-settings.visibility.table.members_row'))
        ->assertSee('thefireflytech.com')
        ->assertSee(__('filament/pages/email-privacy-settings.visibility.table.system_default'));
});

it('shows custom visibility entries in the table', function (): void {
    WorkspaceEmailBlocklist::factory()->protected()->email('legal@acme.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailVisibilityTable::class)
        ->assertSee('legal@acme.com');
});

it('refreshes the visibility table when entries are updated elsewhere on the page', function (): void {
    $component = livewire(EmailVisibilityTable::class)
        ->assertDontSee('new-contact@example.com');

    WorkspaceEmailBlocklist::factory()->protected()->email('new-contact@example.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    $component
        ->dispatch('visibility-entries-updated')
        ->assertSee('new-contact@example.com');
});

it('deletes a custom visibility entry from the table', function (): void {
    $entry = WorkspaceEmailBlocklist::factory()->protected()->email('legal@acme.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailVisibilityTable::class)
        ->callAction(TestAction::make('deleteVisibilityEntry')->arguments(['entry_id' => $entry->id]))
        ->assertNotified();

    expect(WorkspaceEmailBlocklist::query()->whereKey($entry->id)->exists())->toBeFalse();
});

it('forbids a non-admin member from deleting or editing a visibility entry', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    Filament::setTenant($this->workspace);

    $entry = WorkspaceEmailBlocklist::factory()->protected()->domain('acme.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
        'include_subdomains' => false,
    ]);

    livewire(EmailVisibilityTable::class)
        ->call('setVisibilityIncludeSubdomains', (string) $entry->id, true)
        ->assertForbidden();

    livewire(EmailVisibilityTable::class)
        ->callAction(TestAction::make('deleteVisibilityEntry')->arguments(['entry_id' => $entry->id]))
        ->assertForbidden();

    expect($entry->fresh())->not->toBeNull()
        ->and($entry->fresh()->include_subdomains)->toBeFalse();
});

it('updates enforcement level for a custom visibility entry', function (): void {
    $entry = WorkspaceEmailBlocklist::factory()->protected()->email('legal@acme.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    resolve(UpdateWorkspaceEmailVisibilityEntryAction::class)->execute(
        $this->workspace,
        $this->user,
        $entry,
        EmailVisibilityEnforcement::Blocked,
    );

    expect($entry->fresh()->enforcement_level)->toBe(EmailVisibilityEnforcement::Blocked);
});

it('sends a success notification after save', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->callAction('save')
        ->assertNotified('Privacy settings saved.');
});

it('allows an admin member to change record creation settings', function (): void {
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($admin, ['role' => 'admin']);
    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->set('contact_creation_mode', ContactCreationMode::All->value)
        ->callAction('save');

    expect($this->workspace->fresh()->contact_creation_mode)->toBe(ContactCreationMode::All);
});

it('grants the team owner access to the workspace privacy page', function (): void {
    expect(EmailPrivacySettingsPage::canAccess())->toBeTrue();
});

it('grants an admin member access to the workspace privacy page', function (): void {
    $admin = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($admin, ['role' => 'admin']);
    $this->actingAs($admin);
    Filament::setTenant($this->workspace);

    expect(EmailPrivacySettingsPage::canAccess())->toBeTrue();
});

it('denies a non-admin member access to the workspace privacy page', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    Filament::setTenant($this->workspace);

    expect(EmailPrivacySettingsPage::canAccess())->toBeFalse();
});

it('shows record creation mode descriptions and the recommended badge', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->assertSee(__('filament/pages/email-privacy-settings.tabs.record_creation'))
        ->assertSee(__('filament/pages/email-privacy-settings.record_creation.modes.all.description'))
        ->assertSee(__('filament/pages/email-privacy-settings.record_creation.modes.selective.description'))
        ->assertSee(__('filament/pages/email-privacy-settings.record_creation.modes.none.description'))
        ->assertSee(__('filament/pages/email-privacy-settings.record_creation.recommended'))
        ->assertSee(__('filament/pages/email-privacy-settings.record_creation.companies.label'))
        ->assertSee(__('filament/pages/email-privacy-settings.record_creation.description'));
});

it('pre-fills record creation settings from the team on mount', function (): void {
    $this->workspace->update([
        'contact_creation_mode' => ContactCreationMode::None,
        'auto_create_companies' => false,
    ]);

    livewire(EmailPrivacySettingsPage::class)
        ->assertSet('contact_creation_mode', ContactCreationMode::None->value)
        ->assertSet('auto_create_companies', false);
});

it('saves contact_creation_mode when the record creation tab is saved', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->set('contact_creation_mode', ContactCreationMode::All->value)
        ->callAction('save')
        ->assertNotified('Privacy settings saved.');

    expect($this->workspace->fresh()->contact_creation_mode)->toBe(ContactCreationMode::All);
});

it('saves auto_create_companies when the record creation tab is saved', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->set('auto_create_companies', false)
        ->callAction('save')
        ->assertNotified('Privacy settings saved.');

    expect($this->workspace->fresh()->auto_create_companies)->toBeFalse();
});

it('turns off company creation when record creation is saved as None', function (): void {
    $this->workspace->update(['auto_create_companies' => true]);

    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->set('contact_creation_mode', ContactCreationMode::None->value)
        ->assertSet('auto_create_companies', false)
        ->callAction('save')
        ->assertNotified('Privacy settings saved.');

    expect($this->workspace->fresh()->contact_creation_mode)->toBe(ContactCreationMode::None)
        ->and($this->workspace->fresh()->auto_create_companies)->toBeFalse();
});

it('turns off company creation in the action when record creation is None', function (): void {
    $this->workspace->update(['auto_create_companies' => true]);

    resolve(UpdateWorkspaceContactCreationSettingsAction::class)->execute(
        $this->workspace,
        $this->user,
        ContactCreationMode::None,
        true,
    );

    $team = $this->workspace->fresh();

    expect($team->contact_creation_mode)->toBe(ContactCreationMode::None);
    expect($team->auto_create_companies)->toBeFalse();
});

it('disables the company creation switch when record creation is None', function (): void {
    $this->workspace->update(['contact_creation_mode' => ContactCreationMode::None]);

    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->assertSeeHtml('pointer-events-none opacity-60');
});

it('does not save record creation settings when adding a visibility entry', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->set('contact_creation_mode', ContactCreationMode::All->value)
        ->call('setTab', 'visibility')
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => ['blocked@example.com'],
            'visibility_domains' => [],
        ]);

    expect($this->workspace->fresh()->contact_creation_mode)->toBe(ContactCreationMode::Selective);
});

it('does not persist settings when save is called on the visibility tab', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'visibility')
        ->set('contact_creation_mode', ContactCreationMode::All->value)
        ->callAction('save');

    expect($this->workspace->fresh()->contact_creation_mode)->toBe(ContactCreationMode::Selective);
});

it('renders a switch for automatic company creation', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->assertSeeHtml('role="switch"')
        ->assertDontSeeHtml('fi-checkbox-input');
});

it('forbids a non-admin member from changing record creation settings', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($member, ['role' => 'member']);

    expect(fn () => resolve(UpdateWorkspaceContactCreationSettingsAction::class)->execute(
        $this->workspace,
        $member,
        ContactCreationMode::All,
        false,
    ))->toThrow(HttpException::class);

    expect($this->workspace->fresh()->contact_creation_mode)->toBe(ContactCreationMode::Selective);
});

it('forbids a non-admin member from changing workspace email visibility', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($member, ['role' => 'member']);

    expect(fn () => resolve(UpdateWorkspaceEmailVisibilityAction::class)->execute(
        $this->workspace,
        $member,
        [[
            'type' => 'email',
            'value' => 'blocked@example.com',
            'enforcement_level' => EmailVisibilityEnforcement::Blocked,
        ]],
    ))->toThrow(HttpException::class);
});
