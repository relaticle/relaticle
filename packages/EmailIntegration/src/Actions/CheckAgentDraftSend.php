<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

final readonly class CheckAgentDraftSend
{
    /** @throws ValidationException */
    public function execute(User $user, Email $draft): void
    {
        $recipients = $draft->participants->filter(fn (EmailParticipant $participant): bool => in_array(
            $participant->role,
            [EmailParticipantRole::TO, EmailParticipantRole::CC, EmailParticipantRole::BCC],
            true,
        ));

        $account = ConnectedAccount::query()
            ->ownedBy($user, $user->currentWorkspace)
            ->whereKey($draft->connected_account_id)
            ->first();

        $error = match (true) {
            $draft->creation_source === EmailCreationSource::MASS_SEND => 'This draft is a mass send, which goes to each person separately. The user can send it from Drafts in Relaticle.',
            ! $account instanceof ConnectedAccount || ! $account->isSendable() => 'The mailbox of this draft cannot send right now. List your mailboxes to find one that can, and save the draft there.',
            $recipients->where('role', EmailParticipantRole::TO)->isEmpty() => 'This draft has no recipient. Update it with at least one address in to.',
            blank($draft->subject) => 'This draft has no subject. Update it with one.',
            $recipients->count() > PrepareAgentEmail::MAX_RECIPIENTS => PrepareAgentEmail::RECIPIENT_LIMIT_MESSAGE,
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['draft_id' => $error]);
        }
    }
}
