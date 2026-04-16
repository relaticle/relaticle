<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBody;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use RuntimeException;

final readonly class EmailSendingService
{
    /**
     * Send via the connected account's provider and persist the resulting Email record.
     *
     * @param array{
     *     subject: string,
     *     body_html: string,
     *     to: array<array{email: string, name: ?string}>,
     *     cc?: array<array{email: string, name: ?string}>,
     *     bcc?: array<array{email: string, name: ?string}>,
     *     creation_source: EmailCreationSource,
     *     in_reply_to_email_id?: ?string,
     *     batch_id?: ?string,
     *     privacy_tier: EmailPrivacyTier,
     * } $data
     */
    public function send(ConnectedAccount $account, array $data): Email
    {
        $this->assertRateLimitsNotExceeded($account);

        $providerData = $this->dispatchToProvider($account, $data);

        return $this->persistSentEmail($account, $data, $providerData);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{provider_message_id: string, thread_id: string, rfc_message_id: string}
     */
    private function dispatchToProvider(ConnectedAccount $account, array $data): array
    {
        $service = GmailService::forAccount($account);

        /** @var Email|null $inReplyToEmail */
        $inReplyToEmail = isset($data['in_reply_to_email_id'])
            ? Email::query()->whereKey($data['in_reply_to_email_id'])->first()
            : null;

        $payload = [
            'subject' => $data['subject'],
            'body_html' => $data['body_html'],
            'body_text' => strip_tags((string) $data['body_html']),
            'to' => $data['to'],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'from_name' => $account->display_name,
        ];

        if ($inReplyToEmail !== null) {
            $payload['in_reply_to'] = (string) $inReplyToEmail->rfc_message_id;
            $payload['thread_id'] = (string) $inReplyToEmail->thread_id;

            return $service->replyToThread($payload);
        }

        return $service->sendMessage($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{provider_message_id: string, thread_id: string, rfc_message_id: string}  $providerData
     */
    private function persistSentEmail(ConnectedAccount $account, array $data, array $providerData): Email
    {
        /** @var Email|null $inReplyToEmail */
        $inReplyToEmail = isset($data['in_reply_to_email_id'])
            ? Email::query()->whereKey($data['in_reply_to_email_id'])->first()
            : null;

        $email = Email::query()->create([
            'team_id' => $account->team_id,
            'user_id' => $account->user_id,
            'connected_account_id' => $account->getKey(),
            'rfc_message_id' => $providerData['rfc_message_id'],
            'provider_message_id' => $providerData['provider_message_id'],
            'thread_id' => $providerData['thread_id'],
            'in_reply_to' => $inReplyToEmail !== null ? $inReplyToEmail->rfc_message_id : null,
            'subject' => $data['subject'],
            'snippet' => mb_substr(strip_tags((string) $data['body_html']), 0, 255),
            'sent_at' => now(),
            'direction' => EmailDirection::OUTBOUND,
            'folder' => EmailFolder::Sent,
            'status' => EmailStatus::SENT,
            'privacy_tier' => $data['privacy_tier'],
            'has_attachments' => false,
            'is_internal' => false,
            'creation_source' => $data['creation_source'],
            'batch_id' => $data['batch_id'] ?? null,
        ]);

        EmailBody::query()->create([
            'email_id' => $email->getKey(),
            'body_html' => $data['body_html'],
            'body_text' => strip_tags((string) $data['body_html']),
        ]);

        $this->storeParticipants($email, $account, $data);

        return $email;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeParticipants(Email $email, ConnectedAccount $account, array $data): void
    {
        EmailParticipant::query()->create([
            'email_id' => $email->getKey(),
            'email_address' => $account->email_address,
            'name' => $account->display_name,
            'role' => 'from',
        ]);

        foreach (['to', 'cc', 'bcc'] as $role) {
            foreach ($data[$role] ?? [] as $recipient) {
                EmailParticipant::query()->create([
                    'email_id' => $email->getKey(),
                    'email_address' => $recipient['email'],
                    'name' => $recipient['name'] ?? null,
                    'role' => $role,
                ]);
            }
        }
    }

    private function assertRateLimitsNotExceeded(ConnectedAccount $account): void
    {
        if ($account->hourly_send_limit !== null) {
            $hourlySent = Email::query()
                ->where('connected_account_id', $account->getKey())
                ->where('direction', EmailDirection::OUTBOUND)
                ->where('sent_at', '>=', now()->subHour())
                ->count();

            if ($hourlySent >= $account->hourly_send_limit) {
                throw new RuntimeException(
                    "Hourly send limit of {$account->hourly_send_limit} reached for this account."
                );
            }
        }

        if ($account->daily_send_limit !== null) {
            $dailySent = Email::query()
                ->where('connected_account_id', $account->getKey())
                ->where('direction', EmailDirection::OUTBOUND)
                ->where('sent_at', '>=', today())
                ->count();

            if ($dailySent >= $account->daily_send_limit) {
                throw new RuntimeException(
                    "Daily send limit of {$account->daily_send_limit} reached for this account."
                );
            }
        }
    }
}
