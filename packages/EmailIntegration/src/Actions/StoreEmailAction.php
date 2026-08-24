<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailLabel;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailRead;
use Relaticle\EmailIntegration\Services\EmailClassifier;
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
        $storedInlinePaths = [];

        try {
            return DB::transaction(function () use ($connectedAccount, $data, &$storedInlinePaths): Email {
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
                        'is_inline' => $attachment['is_inline'] ?? false,
                        'provider_attachment_id' => $attachment['attachment_id'],
                        'storage_path' => $this->storeInlineData($email, $attachment, $storedInlinePaths),
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

                // Deterministic, rule-based categorisation — cheap string heuristics,
                // no LLM call. Runs inline now that participants/attachments/internal
                // state are all known.
                EmailLabel::query()->create([
                    'email_id' => $email->getKey(),
                    'label' => resolve(EmailClassifier::class)->classify($data, $isInternal)->value,
                    'source' => 'system',
                    'created_at' => now(),
                ]);

                resolve(SyncEmailThreadAction::class)->execute($connectedAccount, $email->thread_id);

                resolve(LinkEmailAction::class)->execute($email);

                return $email;
            });
        } catch (Throwable $exception) {
            Storage::disk(EmailAttachment::DISK)->delete($storedInlinePaths);

            throw $exception;
        }
    }

    /**
     * @param  array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, attachment_id: string|null, inline_data: string|null, is_inline?: bool}  $attachment
     * @param  list<string>  $storedInlinePaths
     */
    private function storeInlineData(Email $email, array $attachment, array &$storedInlinePaths): ?string
    {
        if (($attachment['is_inline'] ?? false) === false) {
            return null;
        }

        if (blank($attachment['inline_data'])) {
            return null;
        }

        $binary = base64_decode(strtr((string) $attachment['inline_data'], '-_', '+/'), true);

        if ($binary === false) {
            return null;
        }

        $path = "email-inline-attachments/{$email->getKey()}/".Str::ulid();

        Storage::disk(EmailAttachment::DISK)->put($path, $binary);
        $storedInlinePaths[] = $path;

        return $path;
    }
}
