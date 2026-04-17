<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;

final readonly class EmailSendingService
{
    /**
     * Send a pre-queued Email via the connected account's provider and update the row.
     */
    public function send(Email $email): Email
    {
        $providerData = $this->dispatchToProvider($email);

        return $this->updateSentEmail($email, $providerData);
    }

    /**
     * @return array{provider_message_id: string, thread_id: string, rfc_message_id: string}
     */
    private function dispatchToProvider(Email $email): array
    {
        /** @var ConnectedAccount $account */
        $account = $email->connectedAccount;

        /** @var EmailBody|null $body */
        $body = $email->body;

        $service = GmailService::forAccount($account);

        $participants = $email->participants;

        $bodyHtml = $body instanceof EmailBody ? (string) $body->body_html : '';
        $bodyText = $body instanceof EmailBody ? (string) $body->body_text : strip_tags($bodyHtml);

        $payload = [
            'subject' => (string) $email->subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'to' => $participants->where('role', 'to')
                ->map(fn (object $participant): array => ['email' => $participant->email_address, 'name' => $participant->name])
                ->values()
                ->all(),
            'cc' => $participants->where('role', 'cc')
                ->map(fn (object $participant): array => ['email' => $participant->email_address, 'name' => $participant->name])
                ->values()
                ->all(),
            'bcc' => $participants->where('role', 'bcc')
                ->map(fn (object $participant): array => ['email' => $participant->email_address, 'name' => $participant->name])
                ->values()
                ->all(),
            'from_name' => $account->display_name,
        ];

        if ($email->in_reply_to !== null) {
            $payload['in_reply_to'] = (string) $email->in_reply_to;
            $payload['thread_id'] = (string) $email->thread_id;

            return $service->replyToThread($payload);
        }

        return $service->sendMessage($payload);
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
