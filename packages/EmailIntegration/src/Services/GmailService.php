<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Google\Service\Exception;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartHeader;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class GmailService
{
    public function __construct(private ConnectedAccount $account, private Gmail $gmail) {}

    public static function forAccount(ConnectedAccount $account): self
    {
        $client = resolve(Factories\GoogleClientFactory::class)->make($account);

        return new self($account, new Gmail($client));
    }

    /**
     * Fetch messages newer than the given historyId (incremental sync).
     * Returns new message IDs and IDs of messages where UNREAD was removed (marked as read).
     *
     * @return array{message_ids: Collection<int, string>, read_message_ids: Collection<int, string>, new_history_id: string}
     */
    public function fetchDelta(string $historyId): array
    {
        $history = $this->gmail->users_history->listUsersHistory('me', [
            'startHistoryId' => $historyId,
            'historyTypes' => ['messageAdded', 'labelsRemoved'],
        ]);

        /** @var array<int, string> $messageIds */
        $messageIds = [];
        /** @var array<int, string> $readMessageIds */
        $readMessageIds = [];

        foreach ($history->getHistory() ?? [] as $item) {
            foreach ($item->getMessagesAdded() ?? [] as $added) {
                $id = $added->getMessage()->getId();
                if (! in_array($id, $messageIds, strict: true)) {
                    $messageIds[] = $id;
                }
            }

            // Track messages where the UNREAD label was removed (user read the email)
            foreach ($item->getLabelsRemoved() ?? [] as $change) {
                if (in_array('UNREAD', $change->getLabelIds() ?? [], strict: true)) {
                    $id = $change->getMessage()->getId();
                    if (! in_array($id, $readMessageIds, strict: true)) {
                        $readMessageIds[] = $id;
                    }
                }
            }
        }

        return [
            'message_ids' => collect($messageIds),
            'read_message_ids' => collect($readMessageIds),
            'new_history_id' => $history->getHistoryId() ?? $historyId,
        ];
    }

    /**
     * Fetch full message details by provider message ID and return a typed DTO.
     */
    public function fetchMessage(string $messageId): FetchedEmailData
    {
        $message = $this->gmail->users_messages->get('me', $messageId, ['format' => 'full']);
        $headers = collect($message->getPayload()->getHeaders())
            ->keyBy(fn (MessagePartHeader $header): string => strtolower((string) $header->getName()));

        $payload = $message->getPayload();

        $labelIds = $message->getLabelIds() ?? [];

        return new FetchedEmailData(
            providerMessageId: $message->getId(),
            rfcMessageId: $headers->get('message-id')?->getValue(),
            threadId: $message->getThreadId(),
            inReplyTo: $headers->get('in-reply-to')?->getValue(),
            subject: $headers->get('subject')?->getValue(),
            snippet: mb_substr(strip_tags((string) $message->getSnippet()), 0, 255),
            sentAt: now()->setTimestamp((int) ($message->getInternalDate() / 1000)),
            direction: in_array('SENT', $labelIds) ? EmailDirection::OUTBOUND : EmailDirection::INBOUND,
            folder: $this->resolveFolder($labelIds),
            hasAttachments: $this->hasAttachments($payload),
            isRead: ! in_array('UNREAD', $labelIds),
            bodyText: $this->extractBody($payload, 'text/plain'),
            bodyHtml: $this->extractBody($payload, 'text/html'),
            participants: $this->extractParticipants($headers),
            attachments: $this->extractAttachments($payload),
        );
    }

    /**
     * Get initial historyId and list of message IDs for backfill.
     *
     * @return array<string, mixed>
     *
     * @throws Exception
     */
    public function fetchInitialMessages(int $daysBack = 90): array
    {
        $after = now()->subDays($daysBack)->timestamp;

        $response = $this->gmail
            ->users_messages
            ->listUsersMessages('me', [
                'q' => "after:$after",
                'maxResults' => 500,
            ]);

        $messageIds = collect($response->getMessages() ?? [])
            ->map(fn (Message $message): string => $message->getId());

        $profile = $this->gmail
            ->users
            ->getProfile('me');

        $historyId = $profile->getHistoryId();

        return [
            'message_ids' => $messageIds,
            'history_id' => $historyId,
        ];
    }

    /**
     * Send a new email (new thread).
     *
     * @param array{
     *     subject: string,
     *     body_html: string,
     *     body_text?: string,
     *     to: array<array{email: string, name: ?string}>,
     *     cc?: array<array{email: string, name: ?string}>,
     *     bcc?: array<array{email: string, name: ?string}>,
     *     from_name?: string,
     * } $data
     * @return array{provider_message_id: string, thread_id: string, rfc_message_id: string}
     *
     * @throws Exception
     */
    public function sendMessage(array $data): array
    {
        $raw = $this->buildMimeMessage($data, null);

        $message = new Message;
        $message->setRaw(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));

        $sent = $this->gmail->users_messages->send('me', $message);

        return [
            'provider_message_id' => $sent->getId(),
            'thread_id' => $sent->getThreadId(),
            'rfc_message_id' => '<'.$sent->getId().'@mail.gmail.com>',
        ];
    }

    /**
     * Reply to an existing thread.
     *
     * @param array{
     *     subject: string,
     *     body_html: string,
     *     body_text?: string,
     *     to: array<array{email: string, name: ?string}>,
     *     cc?: array<array{email: string, name: ?string}>,
     *     bcc?: array<array{email: string, name: ?string}>,
     *     from_name?: string,
     *     in_reply_to: string,
     *     thread_id: string,
     * } $data
     * @return array{provider_message_id: string, thread_id: string, rfc_message_id: string}
     *
     * @throws Exception
     */
    public function replyToThread(array $data): array
    {
        $raw = $this->buildMimeMessage($data, $data['in_reply_to']);

        $message = new Message;
        $message->setRaw(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));
        $message->setThreadId($data['thread_id']);

        $sent = $this->gmail->users_messages->send('me', $message);

        return [
            'provider_message_id' => $sent->getId(),
            'thread_id' => $sent->getThreadId(),
            'rfc_message_id' => '<'.$sent->getId().'@mail.gmail.com>',
        ];
    }

    /**
     * Build a raw RFC 2822 MIME message string.
     *
     * @param  array<string, mixed>  $data
     */
    private function buildMimeMessage(array $data, ?string $inReplyTo): string
    {
        $fromAddress = $this->account->email_address;
        $fromName = $data['from_name'] ?? $this->account->display_name ?? $fromAddress;

        $headers = [];
        $headers[] = 'From: '.$this->formatAddress($fromName, $fromAddress);
        $headers[] = 'To: '.implode(', ', array_map(
            fn (array $recipient): string => $this->formatAddress($recipient['name'] ?? '', $recipient['email']),
            $data['to'] ?? []
        ));

        if (filled($data['cc'])) {
            $headers[] = 'Cc: '.implode(', ', array_map(
                fn (array $recipient): string => $this->formatAddress($recipient['name'] ?? '', $recipient['email']),
                $data['cc']
            ));
        }

        if (filled($data['bcc'])) {
            $headers[] = 'Bcc: '.implode(', ', array_map(
                fn (array $recipient): string => $this->formatAddress($recipient['name'] ?? '', $recipient['email']),
                $data['bcc']
            ));
        }

        $headers[] = 'Subject: =?UTF-8?B?'.base64_encode((string) $data['subject']).'?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/alternative; boundary="boundary_relaticle"';
        $headers[] = 'Date: '.now()->toRfc2822String();

        if ($inReplyTo !== null) {
            $headers[] = 'In-Reply-To: '.$inReplyTo;
            $headers[] = 'References: '.$inReplyTo;
        }

        $bodyText = $data['body_text'] ?? strip_tags($data['body_html'] ?? '');

        $raw = implode("\r\n", $headers)."\r\n\r\n";
        $raw .= "--boundary_relaticle\r\n";
        $raw .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $raw .= $bodyText."\r\n\r\n";
        $raw .= "--boundary_relaticle\r\n";
        $raw .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $raw .= ($data['body_html'] ?? '')."\r\n\r\n";

        return $raw.'--boundary_relaticle--';
    }

    private function formatAddress(string $name, string $email): string
    {
        return filled($name) ? "\"$name\" <$email>" : $email;
    }

    /**
     * @param  array<int, string>  $labelIds
     */
    private function resolveFolder(array $labelIds): EmailFolder
    {
        return match (true) {
            in_array('SENT', $labelIds) => EmailFolder::Sent,
            in_array('DRAFT', $labelIds) => EmailFolder::Drafts,
            in_array('INBOX', $labelIds) => EmailFolder::Inbox,
            default => EmailFolder::Archive,
        };
    }

    private function hasAttachments(MessagePart $payload): bool
    {
        // Large attachments (>25 KB) have an attachmentId set on the body;
        // small ones (<25 KB) carry inline data but still expose a filename.
        return collect($payload->getParts())->contains(
            fn (MessagePart $part): bool => filled($part->getBody()->getAttachmentId()) ||
                filled($part->getFilename()) ||
                ($part->getParts() !== [] && $this->hasAttachments($part))
        );
    }

    /**
     * Download an attachment binary from the Gmail API.
     * Returns the raw decoded bytes.
     *
     * @throws Exception
     */
    public function downloadAttachment(string $messageId, string $attachmentId): string
    {
        $part = $this->gmail->users_messages_attachments->get('me', $messageId, $attachmentId);

        return base64_decode(strtr($part->getData(), '-_', '+/'));
    }

    /**
     * Recursively walk MIME parts and collect attachment metadata.
     * Covers both file attachments (Content-Disposition: attachment) and
     * inline images (Content-Disposition: inline with Content-ID).
     *
     * @return array<int, array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, attachment_id: string|null, inline_data: string|null}>
     */
    private function extractAttachments(MessagePart $payload): array
    {
        $attachments = [];

        foreach ($payload->getParts() as $part) {
            $partHeaders = collect($part->getHeaders())
                ->keyBy(fn (MessagePartHeader $header): string => strtolower((string) $header->getName()));

            $disposition = $partHeaders->get('content-disposition')?->getValue() ?? '';
            $filename = $part->getFilename();

            $contentId = $partHeaders->get('content-id')?->getValue();
            if ($contentId !== null) {
                $contentId = trim($contentId, '<>');
            }

            if (filled($filename) || str_starts_with(strtolower($disposition), 'attachment')) {
                $body = $part->getBody();
                // getAttachmentId() returns empty string when not present (large attachments have it set)
                $gmailAttachmentId = $body->getAttachmentId();
                $attachmentId = filled($gmailAttachmentId) ? $gmailAttachmentId : null;

                $attachments[] = [
                    'filename' => filled($filename) ? $filename : null,
                    'mime_type' => $part->getMimeType(),
                    'size' => $body->getSize(),
                    'content_id' => $contentId,
                    'attachment_id' => $attachmentId,
                    // For small attachments (<25 KB) the binary is inlined; large ones have an attachment_id
                    'inline_data' => $attachmentId === null ? ($body->getData() ?: null) : null,
                ];
            }

            // Recurse into multipart containers (e.g. multipart/mixed, multipart/related)
            if ($part->getParts()) {
                $attachments = array_merge($attachments, $this->extractAttachments($part));
            }
        }

        return $attachments;
    }

    private function extractBody(MessagePart $payload, string $mimeType): ?string
    {
        if ($payload->getMimeType() === $mimeType) {
            $data = $payload->getBody()->getData();
            if ($data) {
                return base64_decode(strtr($data, '-_', '+/'));
            }
        }

        foreach ($payload->getParts() as $part) {
            $result = $this->extractBody($part, $mimeType);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param  Collection<string, MessagePartHeader>  $headers
     * @return array<int, array{email_address: string, name: string|null, role: string}>
     */
    private function extractParticipants(Collection $headers): array
    {
        $participants = [];

        foreach (['from', 'to', 'cc', 'bcc'] as $role) {
            $value = $headers->get($role)?->getValue();
            if (! $value) {
                continue;
            }

            foreach ($this->parseAddressList($value) as $address) {
                $participants[] = array_merge(['role' => $role], $address);
            }
        }

        return $participants;
    }

    /**
     * @return array<int, array{email_address: string, name: string|null}>
     */
    private function parseAddressList(string $raw): array
    {
        $addresses = [];

        $parts = preg_split('/,(?![^<>]*>)/', $raw);

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if ($part === '0') {
                continue;
            }

            if (preg_match('/^(.*?)\s*<([^>]+)>$/', $part, $matches)) {
                $addresses[] = [
                    'name' => trim($matches[1], ' "\''),
                    'email_address' => strtolower(trim($matches[2])),
                ];
            } elseif (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $addresses[] = [
                    'name' => null,
                    'email_address' => strtolower($part),
                ];
            }
        }

        return $addresses;
    }
}
