<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Storage;
use Relaticle\EmailIntegration\Actions\SyncEmailThreadAction;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAttachment;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;

final readonly class EmailSendingService
{
    public function __construct(
        private MailServiceFactoryInterface $mailFactory,
    ) {}

    /**
     * Send a pre-queued Email via the connected account's provider and update the row.
     */
    public function send(Email $email): Email
    {
        $service = $this->mailFactory->make($email->connectedAccount);

        // Retry reconciliation: a prior attempt may have delivered to the provider
        // before its result was persisted (process killed between the network call
        // and the DB write). Re-sending would double-deliver, so first look the
        // message up by the Message-ID we stamped at queue time and adopt it if the
        // provider already has it.
        if ($email->attempts > 1 && $email->rfc_message_id !== null) {
            $existing = $service->findSentMessage($email->rfc_message_id);

            if ($existing !== null) {
                return $this->finalizeSentEmail($email, $existing);
            }
        }

        $providerData = $this->dispatchToProvider($email, $service);

        return $this->finalizeSentEmail($email, $providerData);
    }

    /**
     * @param  array{provider_message_id: string, thread_id: string, rfc_message_id: string}  $providerData
     */
    private function finalizeSentEmail(Email $email, array $providerData): Email
    {
        $email = $this->updateSentEmail($email, $providerData);

        if ($email->thread_id !== null) {
            resolve(SyncEmailThreadAction::class)->execute($email->connectedAccount, $email->thread_id);
        }

        return $email;
    }

    /**
     * @return array{provider_message_id: string, thread_id: string, rfc_message_id: string}
     */
    private function dispatchToProvider(Email $email, MailServiceInterface $service): array
    {
        /** @var ConnectedAccount $account */
        $account = $email->connectedAccount;

        /** @var EmailBody|null $body */
        $body = $email->body;

        $participants = $email->participants;

        $bodyHtml = $body instanceof EmailBody ? (string) $body->body_html : '';
        $bodyText = $body instanceof EmailBody ? (string) $body->body_text : strip_tags($bodyHtml);

        $payload = [
            'subject' => (string) $email->subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'to' => $participants->where('role', 'to')
                ->map(fn (EmailParticipant $participant): array => ['email' => $participant->email_address, 'name' => $participant->name])
                ->values()
                ->all(),
            'cc' => $participants->where('role', 'cc')
                ->map(fn (EmailParticipant $participant): array => ['email' => $participant->email_address, 'name' => $participant->name])
                ->values()
                ->all(),
            'bcc' => $participants->where('role', 'bcc')
                ->map(fn (EmailParticipant $participant): array => ['email' => $participant->email_address, 'name' => $participant->name])
                ->values()
                ->all(),
            'from_name' => $account->display_name,
        ];

        if ($email->rfc_message_id !== null) {
            $payload['rfc_message_id'] = $email->rfc_message_id;
        }

        if ($email->in_reply_to !== null) {
            $payload['in_reply_to'] = (string) $email->in_reply_to;
            $payload['thread_id'] = (string) $email->thread_id;
        }

        $attachments = $this->resolveAttachments($email);

        if ($attachments !== []) {
            $payload['attachments'] = $attachments;
        }

        return $service->sendMessage($payload);
    }

    /**
     * Read the stored bytes for each attachment so the provider can serialize them
     * into the outgoing message.
     *
     * @return array<int, array{filename: string, mime_type: string, content: string}>
     */
    private function resolveAttachments(Email $email): array
    {
        if (! $email->has_attachments) {
            return [];
        }

        $disk = Storage::disk(EmailAttachment::DISK);

        return $email->attachments
            ->filter(fn (EmailAttachment $attachment): bool => $attachment->storage_path !== null && $disk->exists($attachment->storage_path))
            ->map(fn (EmailAttachment $attachment): array => [
                'filename' => (string) $attachment->filename,
                'mime_type' => (string) $attachment->mime_type,
                'content' => (string) $disk->get((string) $attachment->storage_path),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{provider_message_id: string, thread_id: string, rfc_message_id: string}  $providerData
     */
    private function updateSentEmail(Email $email, array $providerData): Email
    {
        $email->update([
            'rfc_message_id' => $providerData['rfc_message_id'],
            'provider_message_id' => $providerData['provider_message_id'],
            'thread_id' => $providerData['thread_id'],
            'sent_at' => now(),
            'status' => EmailStatus::SENT,
            'last_error' => null,
        ]);

        return $email->refresh();
    }
}
