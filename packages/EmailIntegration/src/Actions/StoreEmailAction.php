<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Jobs\ClassifyEmailJob;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailRead;
use Throwable;

final readonly class StoreEmailAction
{
    /**
     * Persist a pre-fetched message to the database.
     * The caller is responsible for deduplication and CRM linking (via LinkEmailJob).
     *
     * @throws Throwable
     */
    public function execute(ConnectedAccount $connectedAccount, FetchedEmailData $data): Email
    {
        $email = DB::transaction(function () use ($connectedAccount, $data): Email {
            $email = Email::query()->create([
                'team_id' => $connectedAccount->team_id,
                'user_id' => $connectedAccount->user_id,
                'connected_account_id' => $connectedAccount->getKey(),
                'rfc_message_id' => $data->rfcMessageId,
                'provider_message_id' => $data->providerMessageId,
                'thread_id' => $data->threadId,
                'in_reply_to' => $data->inReplyTo,
                'subject' => $data->subject,
                'snippet' => $data->snippet,
                'sent_at' => $data->sentAt,
                'direction' => $data->direction,
                'folder' => $data->folder,
                'has_attachments' => $data->hasAttachments,
            ]);

            // Read state is per-viewer; the provider's "already read" flag reflects
            // the owner's mailbox, so seed only the owner's read row.
            if ($data->isRead) {
                EmailRead::query()->create([
                    'email_id' => $email->getKey(),
                    'user_id' => $connectedAccount->user_id,
                    'read_at' => $data->sentAt,
                ]);
            }

            $email->body()->create([
                'body_text' => $data->bodyText,
                'body_html' => $data->bodyHtml,
            ]);

            foreach ($data->participants as $participant) {
                EmailParticipant::query()->create([
                    'email_id' => $email->getKey(),
                    'email_address' => $participant['email_address'],
                    'name' => $participant['name'] ?? null,
                    'role' => $participant['role'],
                ]);
            }

            foreach ($data->attachments as $attachment) {
                EmailAttachment::query()->create([
                    'email_id' => $email->getKey(),
                    'filename' => $attachment['filename'],
                    'mime_type' => $attachment['mime_type'],
                    'size' => $attachment['size'],
                    'content_id' => $attachment['content_id'],
                    'provider_attachment_id' => $attachment['attachment_id'],
                    'storage_path' => null,
                ]);
            }

            // "Internal" means every participant is a member of this workspace.
            // Membership lives in the team_user pivot (plus the owner) — NOT in
            // users.current_team_id, which only reflects a user's *active* team and
            // would misclassify members whose active team is elsewhere.
            $team = Team::query()->find($connectedAccount->team_id);

            $teamUserEmails = ($team?->allUsers() ?? collect())
                ->pluck('email')
                ->map(fn (string $e): string => strtolower($e));

            $participantAddresses = $email->participants()
                ->pluck('email_address')
                ->map(fn (string $e): string => strtolower($e));

            $isInternal = $participantAddresses->isNotEmpty() && $participantAddresses->every(
                fn (string $address): bool => $teamUserEmails->contains($address)
            );

            $email->updateQuietly(['is_internal' => $isInternal]);

            resolve(SyncEmailThreadAction::class)->execute($connectedAccount, $email->thread_id);

            resolve(LinkEmailAction::class)->execute($email);

            return $email;
        });

        // Classify after commit: the EmailObserver only dispatches when participants
        // already exist at create() time, which never holds on the sync path (they
        // are attached above, after the row is inserted), so the job would otherwise
        // never run. afterCommit keeps the queued worker from racing the insert.
        dispatch(new ClassifyEmailJob($email->getKey()))->afterCommit();

        return $email;
    }
}
