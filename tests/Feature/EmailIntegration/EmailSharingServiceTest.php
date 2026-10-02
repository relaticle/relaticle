<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\UpdateEmailSharingAction;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Services\EmailSharingService;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(EmailSharingService::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->service = app(EmailSharingService::class);
});

function makeSharingEmail(array $overrides = []): Email
{
    return Email::factory()->create(array_merge([
        'workspace_id' => test()->workspace->id,
        'user_id' => test()->owner->id,
        'connected_account_id' => test()->account->getKey(),
    ], $overrides));
}

it('creates an EmailShare record', function (): void {
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $email = makeSharingEmail();

    $share = $this->service->shareEmail($email, $this->owner, $viewer, EmailPrivacyTier::FULL);

    expect($share)->toBeInstanceOf(EmailShare::class)
        ->and($share->email_id)->toBe($email->getKey())
        ->and($share->shared_by)->toBe($this->owner->getKey())
        ->and($share->shared_with)->toBe($viewer->getKey())
        ->and($share->tier)->toBe(EmailPrivacyTier::FULL->value);
});

it('updates an existing share when called again for the same viewer', function (): void {
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $email = makeSharingEmail();

    $this->service->shareEmail($email, $this->owner, $viewer, EmailPrivacyTier::METADATA_ONLY);
    $this->service->shareEmail($email, $this->owner, $viewer, EmailPrivacyTier::FULL);

    $shares = EmailShare::where('email_id', $email->getKey())
        ->where('shared_with', $viewer->getKey())
        ->get();

    expect($shares)->toHaveCount(1)
        ->and($shares->first()->tier)->toBe(EmailPrivacyTier::FULL->value);
});

it('removes the share record', function (): void {
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $email = makeSharingEmail();

    $this->service->shareEmail($email, $this->owner, $viewer, EmailPrivacyTier::FULL);
    $this->service->revokeShare($email, $viewer);

    $this->assertDatabaseMissing('email_shares', [
        'email_id' => $email->getKey(),
        'shared_with' => $viewer->getKey(),
    ]);
});

it('does nothing when no share exists', function (): void {
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $email = makeSharingEmail();

    // Should not throw
    $this->service->revokeShare($email, $viewer);

    expect(EmailShare::where('email_id', $email->getKey())->count())->toBe(0);
});

it('updates the email privacy_tier', function (): void {
    $email = makeSharingEmail(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    $this->service->setEmailTier($email, EmailPrivacyTier::FULL);

    expect($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL)
        ->and($email->fresh()->privacy_tier_customized)->toBeTrue();
});

it('shares with a workspace member who is currently working in another workspace', function (): void {
    $member = User::factory()->withWorkspace()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);

    $email = makeSharingEmail();

    app(UpdateEmailSharingAction::class)->execute(
        $email,
        $this->owner,
        EmailPrivacyTier::METADATA_ONLY,
        [['shared_with' => $member->getKey(), 'tier' => EmailPrivacyTier::FULL->value]],
    );

    $this->assertDatabaseHas('email_shares', [
        'email_id' => $email->getKey(),
        'shared_with' => $member->getKey(),
        'tier' => EmailPrivacyTier::FULL->value,
    ]);
});

it('rejects a share target who is not a member of the workspace', function (): void {
    $outsider = User::factory()->withWorkspace()->create();
    $email = makeSharingEmail();

    expect(fn () => app(UpdateEmailSharingAction::class)->execute(
        $email,
        $this->owner,
        EmailPrivacyTier::METADATA_ONLY,
        [['shared_with' => $outsider->getKey(), 'tier' => EmailPrivacyTier::FULL->value]],
    ))->toThrow(HttpException::class);

    $this->assertDatabaseMissing('email_shares', [
        'email_id' => $email->getKey(),
        'shared_with' => $outsider->getKey(),
    ]);
});
