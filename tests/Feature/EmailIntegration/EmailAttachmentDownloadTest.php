<?php

declare(strict_types=1);

use App\Http\Controllers\EmailAttachmentController;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;

mutates(EmailAttachmentController::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
});

function makeAttachmentForUser(User $owner, Team $team, ConnectedAccount $account, array $emailOverrides = [], array $attachmentOverrides = []): EmailAttachment
{
    $email = Email::factory()->create(array_merge([
        'team_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->id,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'provider_message_id' => 'msg-test',
    ], $emailOverrides));

    return EmailAttachment::factory()->create(array_merge([
        'email_id' => $email->id,
        'provider_attachment_id' => 'attach-test',
        'mime_type' => 'text/html',
        'filename' => 'evil.html',
    ], $attachmentOverrides));
}

it('requires authentication', function (): void {
    $attachment = makeAttachmentForUser($this->user, $this->team, $this->account);

    auth()->logout();

    $this->get(route('email-attachments.download', ['attachment' => $attachment->id]))
        ->assertRedirect(route('login'));
});

it('aborts 403 when user belongs to a different team than the email', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $otherTeam->id,
        'user_id' => $otherUser->id,
    ]));

    $attachment = makeAttachmentForUser($otherUser, $otherTeam, $otherAccount);

    $this->get(route('email-attachments.download', ['attachment' => $attachment->id]))
        ->assertForbidden();
});

it('aborts 403 when viewer has no body access (private privacy tier)', function (): void {
    $owner = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($owner, ['role' => 'editor']);

    $email = Email::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $this->account->id,
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'provider_message_id' => 'msg-private',
    ]);
    $attachment = EmailAttachment::factory()->create([
        'email_id' => $email->id,
        'provider_attachment_id' => 'attach-x',
    ]);

    $this->get(route('email-attachments.download', ['attachment' => $attachment->id]))
        ->assertForbidden();
});

it('aborts 404 when provider_attachment_id is missing', function (): void {
    $attachment = makeAttachmentForUser($this->user, $this->team, $this->account, attachmentOverrides: [
        'provider_attachment_id' => null,
    ]);

    $this->get(route('email-attachments.download', ['attachment' => $attachment->id]))
        ->assertNotFound();
});

it('aborts 404 when the connected account is missing', function (): void {
    $attachment = makeAttachmentForUser($this->user, $this->team, $this->account);
    $this->account->delete();

    $this->get(route('email-attachments.download', ['attachment' => $attachment->id]))
        ->assertNotFound();
});
