<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;

mutates(EmailPrivacySettingsPage::class);

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
