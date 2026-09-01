<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\UpdateTeamContactCreationSettingsAction;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(EmailPrivacySettingsPage::class, UpdateTeamEmailPrivacySettingsAction::class, UpdateTeamContactCreationSettingsAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('updates the team default_email_sharing_tier on save', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->set('default_email_sharing_tier', EmailPrivacyTier::FULL->value)
        ->callAction('save');

    expect($this->team->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::FULL);
});

it('shows each sharing tier with its explanation', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->assertSee(EmailPrivacyTier::METADATA_ONLY->getDescription())
        ->assertSee(EmailPrivacyTier::SUBJECT->getDescription())
        ->assertSee(EmailPrivacyTier::FULL->getDescription());
});

it('shows the workspace privacy sections in two columns on large screens', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->assertSeeHtml('--cols-lg: repeat(2, minmax(0, 1fr));');
});

it('creates ProtectedRecipient rows with type email on save', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->set('protected_emails', ['legal@acme.com', 'hr@acme.com'])
        ->callAction('save');

    expect(ProtectedRecipient::query()
        ->where('team_id', $this->team->id)
        ->where('type', 'email')
        ->pluck('value')
        ->sort()
        ->values()
        ->all()
    )->toBe(['hr@acme.com', 'legal@acme.com']);
});

it('creates ProtectedRecipient rows with type domain on save', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->set('protected_domains', ['acme.com', 'rival.io'])
        ->callAction('save');

    expect(ProtectedRecipient::query()
        ->where('team_id', $this->team->id)
        ->where('type', 'domain')
        ->pluck('value')
        ->sort()
        ->values()
        ->all()
    )->toBe(['acme.com', 'rival.io']);
});

it('deletes all prior ProtectedRecipient rows for the team when saving an empty list', function (): void {
    ProtectedRecipient::factory()->email('old@acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    ProtectedRecipient::factory()->domain('acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailPrivacySettingsPage::class)
        ->set('protected_emails', [])
        ->set('protected_domains', [])
        ->callAction('save');

    expect(ProtectedRecipient::query()->where('team_id', $this->team->id)->count())->toBe(0);
});

it('sends a success notification after save', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->callAction('save')
        ->assertNotified('Privacy settings saved.');
});

it('pre-fills default_email_sharing_tier from the team on mount', function (): void {
    $this->team->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT]);

    livewire(EmailPrivacySettingsPage::class)
        ->assertSet('default_email_sharing_tier', EmailPrivacyTier::SUBJECT->value);
});

it('pre-fills protected_emails from existing ProtectedRecipient rows on mount', function (): void {
    ProtectedRecipient::factory()->email('legal@acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailPrivacySettingsPage::class)
        ->assertSet('protected_emails', ['legal@acme.com']);
});

it('pre-fills protected_domains from existing ProtectedRecipient rows on mount', function (): void {
    ProtectedRecipient::factory()->domain('acme.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    livewire(EmailPrivacySettingsPage::class)
        ->assertSet('protected_domains', ['acme.com']);
});

it('forbids a non-admin member from changing team privacy settings', function (): void {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($member, ['role' => 'editor']);

    expect(fn () => resolve(UpdateTeamEmailPrivacySettingsAction::class)->execute(
        $this->team,
        $member,
        EmailPrivacyTier::FULL,
        [],
        [],
    ))->toThrow(HttpException::class);

    expect($this->team->fresh()->default_email_sharing_tier)->not->toBe(EmailPrivacyTier::FULL);
});

it('allows an admin member to change team privacy settings', function (): void {
    $admin = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($admin, ['role' => 'admin']);
    $this->actingAs($admin);
    Filament::setTenant($this->team);

    livewire(EmailPrivacySettingsPage::class)
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

it('autosaves contact_creation_mode for every mailbox on the team', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->set('contact_creation_mode', ContactCreationMode::All->value);

    expect($this->team->fresh()->contact_creation_mode)->toBe(ContactCreationMode::All);
});

it('autosaves auto_create_companies for the workspace', function (): void {
    livewire(EmailPrivacySettingsPage::class)
        ->call('setTab', 'record_creation')
        ->set('auto_create_companies', false);

    expect($this->team->fresh()->auto_create_companies)->toBeFalse();
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
