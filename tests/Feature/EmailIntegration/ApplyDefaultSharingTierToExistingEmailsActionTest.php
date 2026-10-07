<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Relaticle\EmailIntegration\Actions\ApplyDefaultSharingTierToExistingEmailsAction;
use Relaticle\EmailIntegration\Actions\SaveMailboxSharingTierAction;
use Relaticle\EmailIntegration\Actions\SaveWorkspaceEmailSharingDefaultAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(
    ApplyDefaultSharingTierToExistingEmailsAction::class,
    SaveMailboxSharingTierAction::class,
);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->action = resolve(ApplyDefaultSharingTierToExistingEmailsAction::class);
});

function sharingMailbox(User $user, Workspace $workspace, array $attributes = []): ConnectedAccount
{
    $mailbox = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        ...Arr::except($attributes, ['sharing_tier']),
    ]));

    $mailbox->forceFill(['sharing_tier' => $attributes['sharing_tier'] ?? null])->save();

    return $mailbox;
}

function sharingEmail(ConnectedAccount $mailbox, EmailPrivacyTier $tier, array $attributes = []): Email
{
    return Email::factory()->create([
        'workspace_id' => $mailbox->workspace_id,
        'user_id' => $mailbox->user_id,
        'connected_account_id' => $mailbox->getKey(),
        'privacy_tier' => $tier,
        'privacy_tier_customized' => false,
        ...$attributes,
    ]);
}

it('re-stamps only the mail of the mailbox whose level changed', function (): void {
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $sibling = sharingMailbox($this->owner, $this->workspace, ['email_address' => 'second@northwind.test']);
    $mine = sharingEmail($mailbox, EmailPrivacyTier::METADATA_ONLY);
    $other = sharingEmail($sibling, EmailPrivacyTier::METADATA_ONLY);

    resolve(SaveMailboxSharingTierAction::class)->execute($this->owner, $mailbox, EmailPrivacyTier::SUBJECT);

    expect($mine->fresh()->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT)
        ->and($other->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY)
        ->and($mailbox->fresh()->sharing_tier)->toBe(EmailPrivacyTier::SUBJECT);
});

it('leaves the same person mail in another workspace alone', function (): void {
    $elsewhere = Workspace::factory()->create(['user_id' => $this->owner->id]);
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $far = sharingEmail(sharingMailbox($this->owner, $elsewhere), EmailPrivacyTier::FULL);

    resolve(SaveMailboxSharingTierAction::class)->execute($this->owner, $mailbox, EmailPrivacyTier::PRIVATE);

    expect($far->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});

it('keeps an email the owner set by hand', function (): void {
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $custom = sharingEmail($mailbox, EmailPrivacyTier::FULL, ['privacy_tier_customized' => true]);

    resolve(SaveMailboxSharingTierAction::class)->execute($this->owner, $mailbox, EmailPrivacyTier::PRIVATE);

    expect($custom->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});

it('returns a mailbox to the workspace default when its level is cleared', function (): void {
    $this->workspace->update(['default_email_sharing_tier' => EmailPrivacyTier::SUBJECT]);
    $mailbox = sharingMailbox($this->owner, $this->workspace, ['sharing_tier' => EmailPrivacyTier::PRIVATE]);
    $email = sharingEmail($mailbox, EmailPrivacyTier::PRIVATE);

    resolve(SaveMailboxSharingTierAction::class)->execute($this->owner, $mailbox, null);

    expect($mailbox->fresh()->sharing_tier)->toBeNull()
        ->and($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT);
});

it('refuses to set the level of a mailbox someone else owns', function (): void {
    $mailbox = sharingMailbox(User::factory()->create(), $this->workspace);

    resolve(SaveMailboxSharingTierAction::class)->execute($this->owner, $mailbox, EmailPrivacyTier::FULL);
})->throws(HttpException::class);

it('moves only mailboxes without their own level when the workspace default changes', function (): void {
    $follower = sharingEmail(sharingMailbox($this->owner, $this->workspace), EmailPrivacyTier::METADATA_ONLY);
    $chosen = sharingEmail(
        sharingMailbox($this->owner, $this->workspace, ['email_address' => 'own@northwind.test', 'sharing_tier' => EmailPrivacyTier::PRIVATE]),
        EmailPrivacyTier::PRIVATE,
    );

    resolve(SaveWorkspaceEmailSharingDefaultAction::class)->execute($this->workspace, $this->owner, EmailPrivacyTier::SUBJECT);

    expect($follower->fresh()->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT)
        ->and($chosen->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE);
});

it('updates team emails only for mailboxes that follow the workspace default', function (): void {
    $member = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $overrideMember = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($overrideMember, ['role' => 'member']);

    $ownerEmail = sharingEmail(sharingMailbox($this->owner, $this->workspace), EmailPrivacyTier::METADATA_ONLY);
    $memberEmail = sharingEmail(sharingMailbox($member, $this->workspace), EmailPrivacyTier::METADATA_ONLY);
    $overrideEmail = sharingEmail(
        sharingMailbox($overrideMember, $this->workspace, ['sharing_tier' => EmailPrivacyTier::PRIVATE]),
        EmailPrivacyTier::PRIVATE,
    );

    $updated = $this->action->executeForWorkspace($this->workspace, EmailPrivacyTier::FULL);

    expect($updated)->toBe(2)
        ->and($ownerEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($memberEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($overrideEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE);
});

it('leaves mailboxes of other workspaces alone when the workspace default changes', function (): void {
    $elsewhere = Workspace::factory()->create(['user_id' => $this->owner->id]);
    $far = sharingEmail(sharingMailbox($this->owner, $elsewhere), EmailPrivacyTier::METADATA_ONLY);

    $this->action->executeForWorkspace($this->workspace, EmailPrivacyTier::FULL);

    expect($far->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY);
});

it('includes team owner emails when the owner is not on the team_user pivot', function (): void {
    $this->workspace->users()->detach($this->owner->getKey());

    $email = sharingEmail(sharingMailbox($this->owner, $this->workspace), EmailPrivacyTier::METADATA_ONLY);

    $updated = $this->action->executeForWorkspace($this->workspace, EmailPrivacyTier::FULL);

    expect($updated)->toBe(1)
        ->and($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});

it('applies a new workspace default to the mail of a disconnected mailbox', function (): void {
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $email = sharingEmail($mailbox, EmailPrivacyTier::SUBJECT);
    $mailbox->delete();

    resolve(SaveWorkspaceEmailSharingDefaultAction::class)->execute($this->workspace, $this->owner, EmailPrivacyTier::PRIVATE);
    $mailbox->restore();

    expect($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE);
});
