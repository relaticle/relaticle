<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailParticipant;
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
        return DB::transaction(function () use ($connectedAccount, $data): Email {
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
                'read_at' => $data->isRead ? $data->sentAt : null,
            ]);

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

            $teamUserEmails = User::query()
                ->where('current_team_id', $email->team_id)
                ->pluck('email')
                ->map(fn (string $e): string => strtolower($e));

            $participantAddresses = $email->participants()
                ->pluck('email_address')
                ->map(fn (string $e): string => strtolower($e));

            $isInternal = $participantAddresses->isNotEmpty() && $participantAddresses->every(
                fn (string $address): bool => $teamUserEmails->contains($address)
            );

            $email->updateQuietly(['is_internal' => $isInternal]);

            resolve(LinkEmailAction::class)->execute($email);

            return $email;
        });
    }
}
