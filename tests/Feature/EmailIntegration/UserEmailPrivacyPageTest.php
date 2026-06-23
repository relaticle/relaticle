<?php

declare(strict_types=1);

use App\Livewire\App\Email\UserEmailPrivacySettings;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\UpdateUserEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\UserEmailPrivacyPage;

mutates(UserEmailPrivacyPage::class, UserEmailPrivacySettings::class, UpdateUserEmailPrivacySettingsAction::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;
});

it('grants any team member access to the my-privacy page regardless of role', function (): void {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($member, ['role' => 'editor']);
    $this->actingAs($member);
    Filament::setTenant($this->team);

    expect(UserEmailPrivacyPage::canAccess())->toBeTrue();
});

it('persists the user default sharing tier when a member saves their preference', function (): void {
    $member = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($member, ['role' => 'editor']);
    $this->actingAs($member);
    Filament::setTenant($this->team);

    livewire(UserEmailPrivacySettings::class)
        ->set('data.default_email_sharing_tier', EmailPrivacyTier::FULL->value)
        ->call('save')
        ->assertNotified('Email privacy settings saved.');

    expect($member->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::FULL);
});
