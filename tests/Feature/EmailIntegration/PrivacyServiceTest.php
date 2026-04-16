<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;
use Relaticle\EmailIntegration\Services\PrivacyService;

mutates(PrivacyService::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->actingAs($this->owner);
    $this->team = $this->owner->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->service = app(PrivacyService::class);
});

function makePrivacyEmail(array $overrides = []): Email
{
    return Email::factory()->create(array_merge([
        'team_id' => test()->team->id,
        'user_id' => test()->owner->id,
        'connected_account_id' => test()->account->getKey(),
        'is_internal' => false,
    ], $overrides));
}

test('effectiveTier returns FULL when viewer is the email owner', function (): void {
    $email = makePrivacyEmail(['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    $tier = $this->service->effectiveTier($email, $this->owner);

    expect($tier)->toBe(EmailPrivacyTier::FULL);
});

test('effectiveTier returns null when a participant matches a protected email address', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    ProtectedRecipient::factory()->email('protected@sensitive.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->owner->id,
    ]);

    $email = makePrivacyEmail(['privacy_tier' => EmailPrivacyTier::FULL]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'protected@sensitive.com',
    ]);

    $tier = $this->service->effectiveTier($email, $viewer);

    expect($tier)->toBeNull();
});

test('effectiveTier returns null when a participant matches a protected domain', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    ProtectedRecipient::factory()->domain('sensitive.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->owner->id,
    ]);

    $email = makePrivacyEmail(['privacy_tier' => EmailPrivacyTier::FULL]);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'anyone@sensitive.com',
    ]);

    $tier = $this->service->effectiveTier($email, $viewer);

    expect($tier)->toBeNull();
});

test('effectiveTier returns null for internal emails', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    $email = makePrivacyEmail([
        'privacy_tier' => EmailPrivacyTier::FULL,
        'is_internal' => true,
    ]);

    $tier = $this->service->effectiveTier($email, $viewer);

    expect($tier)->toBeNull();
});

test('effectiveTier uses per-email share tier when a share exists for the viewer', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    $email = makePrivacyEmail(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    EmailShare::factory()->tier(EmailPrivacyTier::SUBJECT)->create([
        'email_id' => $email->getKey(),
        'shared_by' => $this->owner->id,
        'shared_with' => $viewer->id,
    ]);

    $tier = $this->service->effectiveTier($email, $viewer);

    expect($tier)->toBe(EmailPrivacyTier::SUBJECT);
});

test('effectiveTier falls back to the email privacy_tier when no share exists', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    $email = makePrivacyEmail(['privacy_tier' => EmailPrivacyTier::SUBJECT]);

    $tier = $this->service->effectiveTier($email, $viewer);

    expect($tier)->toBe(EmailPrivacyTier::SUBJECT);
});

test('effectiveTier returns null when email privacy_tier is PRIVATE and viewer is not owner', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    $email = makePrivacyEmail(['privacy_tier' => EmailPrivacyTier::PRIVATE]);

    $tier = $this->service->effectiveTier($email, $viewer);

    expect($tier)->toBeNull();
});

test('effectiveTier returns METADATA_ONLY for a non-owner when email is metadata_only', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    $email = makePrivacyEmail(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    $tier = $this->service->effectiveTier($email, $viewer);

    expect($tier)->toBe(EmailPrivacyTier::METADATA_ONLY);
});

test('effectiveTier returns FULL for a non-owner when email is FULL', function (): void {
    $viewer = User::factory()->create(['current_team_id' => $this->team->id]);

    $email = makePrivacyEmail(['privacy_tier' => EmailPrivacyTier::FULL]);

    $tier = $this->service->effectiveTier($email, $viewer);

    expect($tier)->toBe(EmailPrivacyTier::FULL);
});

test('defaultTierForUser returns user-level tier when the user has one set', function (): void {
    $this->owner->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT]);

    $tier = $this->service->defaultTierForUser($this->owner);

    expect($tier)->toBe(EmailPrivacyTier::SUBJECT);
});

test('defaultTierForUser falls back to team default when user has no preference', function (): void {
    $this->owner->update(['default_email_sharing_tier' => null]);
    $this->team->update(['default_email_sharing_tier' => EmailPrivacyTier::FULL]);

    $tier = $this->service->defaultTierForUser($this->owner->fresh());

    expect($tier)->toBe(EmailPrivacyTier::FULL);
});

test('effectiveTier owner access is not blocked by protected recipient', function (): void {
    ProtectedRecipient::factory()->email('protected@sensitive.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->owner->id,
    ]);

    $email = makePrivacyEmail(['privacy_tier' => EmailPrivacyTier::FULL]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'protected@sensitive.com',
    ]);

    // Owner can still see their own email even when a protected recipient is involved
    $tier = $this->service->effectiveTier($email, $this->owner);

    expect($tier)->toBe(EmailPrivacyTier::FULL);
});
