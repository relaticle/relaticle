<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\ApplyDefaultSharingTierToExistingEmailsAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(ApplyDefaultSharingTierToExistingEmailsAction::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->action = app(ApplyDefaultSharingTierToExistingEmailsAction::class);
});

function makeRetroactiveEmail(array $overrides = []): Email
{
    return Email::factory()->create(array_merge([
        'workspace_id' => test()->workspace->id,
        'user_id' => test()->owner->id,
        'connected_account_id' => test()->account->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'privacy_tier_customized' => false,
    ], $overrides));
}

it('updates non-customized emails for a user', function (): void {
    $email = makeRetroactiveEmail();
    $customized = makeRetroactiveEmail(['privacy_tier_customized' => true]);

    $updated = $this->action->executeForUser($this->owner, EmailPrivacyTier::FULL);

    expect($updated)->toBe(1)
        ->and($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($customized->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY);
});

it('updates team emails only for members who follow the workspace default', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id, 'default_email_sharing_tier' => null]);
    $this->workspace->users()->attach($member, ['role' => 'member']);

    $memberAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $member->id,
    ]));

    $ownerEmail = makeRetroactiveEmail();
    $memberEmail = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $member->id,
        'connected_account_id' => $memberAccount->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'privacy_tier_customized' => false,
    ]);

    $overrideMember = User::factory()->create([
        'current_workspace_id' => $this->workspace->id,
        'default_email_sharing_tier' => EmailPrivacyTier::PRIVATE,
    ]);
    $this->workspace->users()->attach($overrideMember, ['role' => 'member']);

    $overrideAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $overrideMember->id,
    ]));

    $overrideEmail = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $overrideMember->id,
        'connected_account_id' => $overrideAccount->getKey(),
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'privacy_tier_customized' => false,
    ]);

    $updated = $this->action->executeForTeam($this->workspace, EmailPrivacyTier::FULL);

    expect($updated)->toBe(2)
        ->and($ownerEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($memberEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($overrideEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE);
});

it('updates non-customized emails across every workspace for a user', function (): void {
    $otherTeam = Workspace::factory()->create([
        'user_id' => $this->owner->getKey(),
        'default_email_sharing_tier' => EmailPrivacyTier::METADATA_ONLY,
    ]);
    $this->owner->workspaces()->attach($otherTeam, ['role' => 'admin']);

    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $otherTeam->getKey(),
        'user_id' => $this->owner->getKey(),
    ]));

    $currentWorkspaceEmail = makeRetroactiveEmail();
    $otherTeamEmail = Email::factory()->create([
        'workspace_id' => $otherTeam->getKey(),
        'user_id' => $this->owner->getKey(),
        'connected_account_id' => $otherAccount->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'privacy_tier_customized' => false,
    ]);

    $updated = $this->action->executeForUser($this->owner, EmailPrivacyTier::FULL);

    expect($updated)->toBe(2)
        ->and($currentWorkspaceEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($otherTeamEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});

it('resolves each workspace default when resetting a user override', function (): void {
    $privateTeam = Workspace::factory()->create([
        'user_id' => $this->owner->getKey(),
        'default_email_sharing_tier' => EmailPrivacyTier::PRIVATE,
    ]);
    $this->owner->workspaces()->attach($privateTeam, ['role' => 'admin']);
    $this->workspace->update(['default_email_sharing_tier' => EmailPrivacyTier::FULL]);

    $privateAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $privateTeam->getKey(),
        'user_id' => $this->owner->getKey(),
    ]));

    $fullWorkspaceEmail = makeRetroactiveEmail(['privacy_tier' => EmailPrivacyTier::SUBJECT]);
    $privateWorkspaceEmail = Email::factory()->create([
        'workspace_id' => $privateTeam->getKey(),
        'user_id' => $this->owner->getKey(),
        'connected_account_id' => $privateAccount->getKey(),
        'privacy_tier' => EmailPrivacyTier::SUBJECT,
        'privacy_tier_customized' => false,
    ]);

    $updated = $this->action->executeForUserUsingWorkspaceDefaults($this->owner);

    expect($updated)->toBe(2)
        ->and($fullWorkspaceEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($privateWorkspaceEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE);
});

it('includes team owner emails when the owner is not on the team_user pivot', function (): void {
    $this->workspace->users()->detach($this->owner->getKey());

    $email = makeRetroactiveEmail();

    $updated = $this->action->executeForTeam($this->workspace, EmailPrivacyTier::FULL);

    expect($updated)->toBe(1)
        ->and($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});
