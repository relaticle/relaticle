<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\UpdateTeamContactCreationSettingsAction;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailVisibilityAction;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailVisibilityEntryAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;
use Relaticle\EmailIntegration\Livewire\EmailVisibilityTable;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(
    EmailPrivacySettingsPage::class,
    EmailVisibilityTable::class,
    UpdateTeamEmailPrivacySettingsAction::class,
    UpdateTeamEmailVisibilityAction::class,
    UpdateTeamEmailVisibilityEntryAction::class,
    UpdateTeamContactCreationSettingsAction::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('updates the team default_email_sharing_tier on save', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'sharing')
        ->set('default_email_sharing_tier', EmailPrivacyTier::FULL->value)
        ->callAction('save');

    expect($this->team->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::FULL);
});

it('shows each sharing tier with its explanation', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'sharing')
        ->assertSee(EmailPrivacyTier::METADATA_ONLY->getDescription())
        ->assertSee(EmailPrivacyTier::SUBJECT->getDescription())
        ->assertSee(EmailPrivacyTier::FULL->getDescription());
});

it('creates protected TeamEmailBlocklist rows from the add contacts modal', function (): void {
    livewire(EmailVisibilityTable::class)
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => ['legal@acme.com', 'hr@acme.com'],
            'visibility_domains' => [],
            'enforcement_level' => EmailVisibilityEnforcement::Protected->value,
        ])
        ->assertNotified();

    expect(TeamEmailBlocklist::query()
        ->where('team_id', $this->team->id)
        ->where('enforcement_level', EmailVisibilityEnforcement::Protected->value)
        ->where('type', 'email')
        ->pluck('value')
        ->sort()
        ->values()
        ->all()
    )->toBe(['hr@acme.com', 'legal@acme.com']);
});

it('creates blocked TeamEmailBlocklist rows from the add contacts modal', function (): void {
    livewire(EmailVisibilityTable::class)
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => [],
            'visibility_domains' => ['spam.com'],
            'enforcement_level' => EmailVisibilityEnforcement::Blocked->value,
        ])
        ->assertNotified();

    expect(TeamEmailBlocklist::query()
        ->where('team_id', $this->team->id)
        ->where('enforcement_level', EmailVisibilityEnforcement::Blocked->value)
        ->where('type', 'domain')
        ->pluck('value')
        ->all()
    )->toBe(['spam.com']);
});

it('normalizes domain urls when adding visibility entries', function (): void {
    livewire(EmailVisibilityTable::class)
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => [],
            'visibility_domains' => ['https://mail.outskill.com', 'outskill.com'],
            'enforcement_level' => EmailVisibilityEnforcement::Protected->value,
        ])
        ->assertNotified();

    expect(TeamEmailBlocklist::query()
        ->where('team_id', $this->team->id)
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
    TeamEmailBlocklist::factory()->protected()->email('legal@acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailVisibilityTable::class)
        ->assertSee('legal@acme.com');
});

it('deletes a custom visibility entry from the table', function (): void {
    $entry = TeamEmailBlocklist::factory()->protected()->email('legal@acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailVisibilityTable::class)
        ->callAction('deleteVisibilityEntry', arguments: ['entry_id' => $entry->id])
        ->assertNotified();

    expect(TeamEmailBlocklist::query()->whereKey($entry->id)->exists())->toBeFalse();
});

it('updates enforcement level for a custom visibility entry', function (): void {
    $entry = TeamEmailBlocklist::factory()->protected()->email('legal@acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    resolve(UpdateTeamEmailVisibilityEntryAction::class)->execute(
        $this->team,
        $this->user,
        $entry,
        EmailVisibilityEnforcement::Blocked,
    );

    expect($entry->fresh()->enforcement_level)->toBe(EmailVisibilityEnforcement::Blocked);
});

it('sends a success notification after save', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'sharing')
        ->callAction('save')
        ->assertNotified('Privacy settings saved.');
});

it('pre-fills default_email_sharing_tier from the team on mount', function (): void {
    $this->team->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT]);

    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'sharing')
        ->assertSet('default_email_sharing_tier', EmailPrivacyTier::SUBJECT->value);
});

it('forbids a non-admin member from changing team privacy settings', function (): void {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($member, ['role' => 'editor']);

    expect(fn () => resolve(UpdateTeamEmailPrivacySettingsAction::class)->execute(
        $this->team,
        $member,
        EmailPrivacyTier::FULL,
    ))->toThrow(HttpException::class);

    expect($this->team->fresh()->default_email_sharing_tier)->not->toBe(EmailPrivacyTier::FULL);
});

it('allows an admin member to change team privacy settings', function (): void {
    $admin = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($admin, ['role' => 'admin']);
    $this->actingAs($admin);
    Filament::setTenant($this->team);

    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'sharing')
        ->set('default_email_sharing_tier', EmailPrivacyTier::FULL->value)
        ->callAction('save');

    expect($this->team->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::FULL);
});

it('grants the team owner access to the workspace privacy page', function (): void {
    expect(EmailPrivacySettingsPage::canAccess())->toBeTrue();
});

it('grants an admin member access to the workspace privacy page', function (): void {
    $admin = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($admin, ['role' => 'admin']);
    $this->actingAs($admin);
    Filament::setTenant($this->team);

    expect(EmailPrivacySettingsPage::canAccess())->toBeTrue();
});

it('denies a non-admin member access to the workspace privacy page', function (): void {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($member, ['role' => 'editor']);
    $this->actingAs($member);
    Filament::setTenant($this->team);

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
    $this->team->update([
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

    expect($this->team->fresh()->contact_creation_mode)->toBe(ContactCreationMode::All);
});

it('saves auto_create_companies when the record creation tab is saved', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->set('auto_create_companies', false)
        ->callAction('save')
        ->assertNotified('Privacy settings saved.');

    expect($this->team->fresh()->auto_create_companies)->toBeFalse();
});

it('does not save record creation settings when adding a visibility entry', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->set('contact_creation_mode', ContactCreationMode::All->value)
        ->call('setTab', 'visibility');

    livewire(EmailVisibilityTable::class)
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => ['blocked@example.com'],
            'visibility_domains' => [],
            'enforcement_level' => EmailVisibilityEnforcement::Blocked->value,
        ]);

    expect($this->team->fresh()->contact_creation_mode)->toBe(ContactCreationMode::Selective);
});

it('does not persist settings when save is called on the visibility tab', function (): void {
    $this->team->update(['default_email_sharing_tier' => EmailPrivacyTier::METADATA_ONLY]);

    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'visibility')
        ->set('default_email_sharing_tier', EmailPrivacyTier::FULL->value)
        ->callAction('save');

    expect($this->team->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::METADATA_ONLY);
});

it('renders a switch for automatic company creation', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->assertSeeHtml('role="switch"')
        ->assertDontSeeHtml('fi-checkbox-input');
});

it('forbids a non-admin member from changing record creation settings', function (): void {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($member, ['role' => 'editor']);

    expect(fn () => resolve(UpdateTeamContactCreationSettingsAction::class)->execute(
        $this->team,
        $member,
        ContactCreationMode::All,
        false,
    ))->toThrow(HttpException::class);

    expect($this->team->fresh()->contact_creation_mode)->toBe(ContactCreationMode::Selective);
});

it('forbids a non-admin member from changing workspace email visibility', function (): void {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($member, ['role' => 'editor']);

    expect(fn () => resolve(UpdateTeamEmailVisibilityAction::class)->execute(
        $this->team,
        $member,
        [[
            'type' => 'email',
            'value' => 'blocked@example.com',
            'enforcement_level' => EmailVisibilityEnforcement::Blocked,
        ]],
    ))->toThrow(HttpException::class);
});

it('does not save sharing settings from the visibility modal', function (): void {
    $this->team->update(['default_email_sharing_tier' => EmailPrivacyTier::METADATA_ONLY]);

    livewire(EmailVisibilityTable::class)
        ->callAction('addVisibilityContact', data: [
            'visibility_emails' => ['blocked@example.com'],
            'visibility_domains' => [],
            'enforcement_level' => EmailVisibilityEnforcement::Blocked->value,
        ])
        ->assertNotified();

    expect($this->team->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::METADATA_ONLY);

    $this->assertDatabaseHas(TeamEmailBlocklist::class, [
        'team_id' => $this->team->id,
        'value' => 'blocked@example.com',
    ]);
});
