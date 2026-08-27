<?php

declare(strict_types=1);

use App\Livewire\App\Email\UserEmailPrivacySettings;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\UpdateUserEmailPrivacySettingsAction;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\UserEmailPrivacyPage;
use Relaticle\EmailIntegration\Models\EmailBlocklist;

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

it('shows existing blocked addresses and domains as separate tag inputs', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    EmailBlocklist::factory()->create([
        'user_id' => $this->owner->id,
        'team_id' => $this->team->id,
        'type' => EmailBlocklistType::EMAIL,
        'value' => 'noisy@example.com',
    ]);

    EmailBlocklist::factory()->create([
        'user_id' => $this->owner->id,
        'team_id' => $this->team->id,
        'type' => EmailBlocklistType::DOMAIN,
        'value' => 'spammy.com',
    ]);

    livewire(UserEmailPrivacySettings::class)
        ->assertSet('data.blocklist_emails', ['noisy@example.com'])
        ->assertSet('data.blocklist_domains', ['spammy.com']);
});

it('renders the sharing cards and separate blocklist tag inputs', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    livewire(UserEmailPrivacySettings::class)
        ->assertSee('Use workspace default')
        ->assertSee('Blocked addresses')
        ->assertSee('Emails involving these addresses are hidden from your view.')
        ->assertSee('Blocked domains')
        ->assertSee('Emails involving any address at these domains are hidden from your view.');
});

it('saves addresses and domains from the separate tag inputs', function (): void {
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    livewire(UserEmailPrivacySettings::class)
        ->set('data.blocklist_emails', ['NOISY@Example.com'])
        ->set('data.blocklist_domains', ['Spammy.com'])
        ->call('save');

    $this->assertDatabaseHas(EmailBlocklist::class, [
        'user_id' => $this->owner->id,
        'team_id' => $this->team->id,
        'type' => EmailBlocklistType::EMAIL->value,
        'value' => 'noisy@example.com',
    ]);

    $this->assertDatabaseHas(EmailBlocklist::class, [
        'user_id' => $this->owner->id,
        'team_id' => $this->team->id,
        'type' => EmailBlocklistType::DOMAIN->value,
        'value' => 'spammy.com',
    ]);
});
