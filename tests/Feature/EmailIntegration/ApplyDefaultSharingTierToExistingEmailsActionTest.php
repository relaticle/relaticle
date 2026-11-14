<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Relaticle\EmailIntegration\Actions\ApplyDefaultSharingTierToExistingEmailsAction;
use Relaticle\EmailIntegration\Actions\SaveMailboxSharingTier;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(
    ApplyDefaultSharingTierToExistingEmailsAction::class,
    SaveMailboxSharingTier::class,
);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);
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

    resolve(SaveMailboxSharingTier::class)->execute($this->owner, $mailbox, EmailPrivacyTier::SUBJECT);

    expect($mine->fresh()->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT)
        ->and($other->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY)
        ->and($mailbox->fresh()->sharing_tier)->toBe(EmailPrivacyTier::SUBJECT);
});

it('brings drifted mail back to the mailbox level when the same level is saved again', function (): void {
    $mailbox = sharingMailbox($this->owner, $this->workspace, ['sharing_tier' => EmailPrivacyTier::SUBJECT]);
    $drifted = sharingEmail($mailbox, EmailPrivacyTier::FULL);

    resolve(SaveMailboxSharingTier::class)->execute($this->owner, $mailbox, EmailPrivacyTier::SUBJECT);

    expect($drifted->fresh()->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT);
});

it('leaves the same person mail in another workspace alone', function (): void {
    $elsewhere = Workspace::factory()->create(['user_id' => $this->owner->id]);
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $far = sharingEmail(sharingMailbox($this->owner, $elsewhere), EmailPrivacyTier::FULL);

    resolve(SaveMailboxSharingTier::class)->execute($this->owner, $mailbox, EmailPrivacyTier::PRIVATE);

    expect($far->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});

it('keeps an email the owner set by hand', function (): void {
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $custom = sharingEmail($mailbox, EmailPrivacyTier::FULL, ['privacy_tier_customized' => true]);

    resolve(SaveMailboxSharingTier::class)->execute($this->owner, $mailbox, EmailPrivacyTier::PRIVATE);

    expect($custom->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
});

it('refuses to set the level of a mailbox someone else owns', function (): void {
    $mailbox = sharingMailbox(User::factory()->create(), $this->workspace);

    resolve(SaveMailboxSharingTier::class)->execute($this->owner, $mailbox, EmailPrivacyTier::FULL);
})->throws(HttpException::class);

it('never widens automated mail but still narrows it when its owner lowers the mailbox level', function (): void {
    $mailbox = sharingMailbox($this->owner, $this->workspace);
    $closed = sharingEmail($mailbox, EmailPrivacyTier::PRIVATE, ['automated_reason' => AutomatedReason::cases()[0]]);
    $open = sharingEmail($mailbox, EmailPrivacyTier::FULL, ['automated_reason' => AutomatedReason::cases()[0]]);

    resolve(SaveMailboxSharingTier::class)->execute($this->owner, $mailbox, EmailPrivacyTier::SUBJECT);

    expect($closed->fresh()->privacy_tier)->toBe(EmailPrivacyTier::PRIVATE)
        ->and($open->fresh()->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT);
});
