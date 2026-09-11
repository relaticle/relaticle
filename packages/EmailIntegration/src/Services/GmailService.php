<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Google\Service\Exception as GoogleServiceException;
use Google\Service\Gmail;
use Google\Service\Gmail\Message;
use Google\Service\Gmail\MessagePart;
use Google\Service\Gmail\MessagePartHeader;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailCategory;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Exceptions\MailHistoryExpired;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;

final readonly class GmailService implements MailServiceInterface
{
    public function __construct(private ConnectedAccount $account, private Gmail $gmail) {}

    /**
     * Fetch messages newer than the given cursor (incremental sync).
     * Returns new message IDs and IDs of messages where UNREAD was removed (marked as read).
     *
     * @throws MailHistoryExpired when Gmail invalidates startHistoryId (HTTP 404)
     */
    public function fetchDelta(string $cursor): MailDeltaResult
    {
        /** @var array<int, string> $messageIds */
        $messageIds = [];
        // id => isRead, last-write-wins. UNREAD can be added and removed on the
        // same message across history records in one sync; keying by id keeps
        // the LATEST state so a message never lands in both lists (which the
        // sync job would then apply unread-last, overriding the final state).
        /** @var array<string, bool> $readState */
        $readState = [];

        $newCursor = $cursor;
        $pageToken = null;

        // The History API paginates: a single page may not contain all changes since
        // the cursor. Follow getNextPageToken() until exhausted, otherwise multi-page
        // history is dropped and newCursor jumps ahead of unfetched messages (data loss).
        do {
            $params = [
                'startHistoryId' => $cursor,
                'historyTypes' => ['messageAdded', 'labelRemoved', 'labelAdded'],
            ];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            try {
                $history = $this->gmail->users_history->listUsersHistory('me', $params);
            } catch (GoogleServiceException $exception) {
                if ($exception->getCode() === 404) {
                    throw MailHistoryExpired::forAccount((string) $this->account->getKey());
                }

                throw $exception;
            }

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
                        $readState[$change->getMessage()->getId()] = true;
                    }
                }

                // Track messages where the UNREAD label was re-added (marked unread again)
                foreach ($item->getLabelsAdded() ?? [] as $change) {
                    if (in_array('UNREAD', $change->getLabelIds() ?? [], strict: true)) {
                        $readState[$change->getMessage()->getId()] = false;
                    }
                }
            }

            // historyId is the same on every page (= head of history at request time);
            // keep the latest non-null so the cursor advances only past fully-paged history.
            $newCursor = (string) ($history->getHistoryId() ?? $newCursor);

            $pageToken = $history->getNextPageToken();
        } while ($pageToken !== null && $pageToken !== '');

        /** @var array<int, string> $readMessageIds */
        $readMessageIds = [];
        /** @var array<int, string> $unreadMessageIds */
        $unreadMessageIds = [];

        foreach ($readState as $id => $isRead) {
            if ($isRead) {
                $readMessageIds[] = (string) $id;
            } else {
                $unreadMessageIds[] = (string) $id;
            }
        }

        return new MailDeltaResult(
            messageIds: collect($messageIds),
            readMessageIds: collect($readMessageIds),
            newCursor: $newCursor,
            unreadMessageIds: collect($unreadMessageIds),
        );
    }

    /**
     * Fetch full message details by provider message ID and return a typed DTO.
     */
    public function fetchMessage(string $messageId): FetchedEmailData
    {
        $message = $this->gmail->users_messages->get('me', $messageId, ['format' => 'full']);
        $headers = $this->indexHeaders($message->getPayload());

        $payload = $message->getPayload();

        $labelIds = $message->getLabelIds() ?? [];
        $bodyText = $this->extractBody($payload, 'text/plain');
        $bodyHtml = $this->extractBody($payload, 'text/html');
        $referencedContentIds = $this->extractReferencedContentIds(($bodyHtml ?? '').' '.($bodyText ?? ''));
        $attachments = $this->extractAttachments($payload, $referencedContentIds);

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
            hasAttachments: collect($attachments)->contains(fn (array $attachment): bool => ! $attachment['is_inline']),
            isRead: ! in_array('UNREAD', $labelIds),
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
            participants: $this->extractParticipants($headers),
            attachments: $attachments,
            providerCategory: $this->resolveProviderCategory($labelIds),
        );
    }

    /**
     * Fetch one page of message IDs for the initial backfill.
     */
    public function initialBackfill(?int $daysBack = null, ?string $pageToken = null): MailBackfillPage
    {
        $cursor = null;

        if ($pageToken === null) {
            // Capture the cursor before listing so mail that arrives mid-backfill is not missed.
            $profile = $this->gmail->users->getProfile('me');
            $cursor = (string) $profile->getHistoryId();
        }

        $params = [
            'maxResults' => 500,
            // Gmail's messages.list includes drafts unless we exclude them.
            // Drafts have no SENT label, so the store job would treat them as
            // inbound and share unsent mail with the workspace.
            'q' => '-in:drafts',
        ];

        if ($daysBack !== null && $daysBack > 0) {
            $params['q'] .= ' after:'.now()->subDays($daysBack)->timestamp;
        }

        if ($pageToken !== null && $pageToken !== '') {
            $params['pageToken'] = $pageToken;
        }

        $response = $this->gmail->users_messages->listUsersMessages('me', $params);
        $nextPageToken = $response->getNextPageToken();

        $estimate = $pageToken === null ? $response->getResultSizeEstimate() : null;

        return new MailBackfillPage(
            messageIds: $this->pluckMessageIds($response->getMessages())->unique()->values(),
            nextPageToken: ($nextPageToken !== null && $nextPageToken !== '') ? $nextPageToken : null,
            cursor: $cursor,
            estimatedTotal: is_numeric($estimate) ? (int) $estimate : null,
        );
    }

    /**
     * Send a new email or reply to an existing thread.
     * Pass `in_reply_to` to send as a reply. Pass `thread_id` only when it
     * belongs to this mailbox; Gmail rejects a thread id from another account.
     *
     * @param array{
     *     subject: string,
     *     body_html: string,
     *     body_text?: string,
     *     to: array<int, array{email: string, name: ?string}>,
     *     cc?: array<int, array{email: string, name: ?string}>,
     *     bcc?: array<int, array{email: string, name: ?string}>,
     *     from_name?: string,
     *     in_reply_to?: string,
     *     thread_id?: string,
     *     rfc_message_id?: string,
     *     attachments?: array<int, array{filename: string, mime_type: string, content: string, is_inline?: bool, content_id?: ?string}>,
     * } $data
     * @return array{provider_message_id: string, thread_id: string, rfc_message_id: string}
     */
    public function sendMessage(array $data): array
    {
        $raw = $this->buildMimeMessage($data, $data['in_reply_to'] ?? null);

        $message = new Message;
        $message->setRaw(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));

        $threadId = $data['thread_id'] ?? null;

        if (is_string($threadId) && $threadId !== '') {
            $message->setThreadId($threadId);
        }

        $sent = $this->gmail->users_messages->send('me', $message);

        return [
            'provider_message_id' => $sent->getId(),
            'thread_id' => $sent->getThreadId(),
            // Prefer the Message-ID we stamped on the outgoing MIME (used for retry
            // de-duplication); fall back to a Gmail-derived id for older callers.
            'rfc_message_id' => $data['rfc_message_id'] ?? '<'.$sent->getId().'@mail.gmail.com>',
        ];
    }

    public function findSentMessage(string $rfcMessageId): ?array
    {
        $list = $this->gmail->users_messages->listUsersMessages('me', [
            'q' => 'rfc822msgid:'.trim($rfcMessageId, '<>'),
            'maxResults' => 1,
        ]);

        $messages = $list->getMessages();

        if ($messages === null || $messages === []) {
            return null;
        }

        $message = $this->gmail->users_messages->get('me', $messages[0]->getId(), ['format' => 'minimal']);

        return [
            'provider_message_id' => $message->getId(),
            'thread_id' => $message->getThreadId(),
            'rfc_message_id' => $rfcMessageId,
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

        if (filled($data['cc'] ?? null)) {
            $headers[] = 'Cc: '.implode(', ', array_map(
                fn (array $recipient): string => $this->formatAddress($recipient['name'] ?? '', $recipient['email']),
                $data['cc']
            ));
        }

        if (filled($data['bcc'] ?? null)) {
            $headers[] = 'Bcc: '.implode(', ', array_map(
                fn (array $recipient): string => $this->formatAddress($recipient['name'] ?? '', $recipient['email']),
                $data['bcc']
            ));
        }

        $attachments = $data['attachments'] ?? [];
        $inlineAttachments = [];
        $fileAttachments = [];

        foreach ($attachments as $attachment) {
            if (($attachment['is_inline'] ?? false) === true && filled($attachment['content_id'] ?? null)) {
                $inlineAttachments[] = $attachment;
            } else {
                $fileAttachments[] = $attachment;
            }
        }

        $headers[] = 'Subject: =?UTF-8?B?'.base64_encode((string) $data['subject']).'?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = $this->rootContentTypeHeader($inlineAttachments !== [], $fileAttachments !== []);
        $headers[] = 'Date: '.now()->toRfc2822String();

        // Stamp our own Message-ID so a retry can find an already-sent copy via
        // Gmail's rfc822msgid search instead of re-delivering the message.
        if (isset($data['rfc_message_id'])) {
            $headers[] = 'Message-ID: '.$data['rfc_message_id'];
        }

        if ($inReplyTo !== null) {
            $headers[] = 'In-Reply-To: '.$inReplyTo;
            $headers[] = 'References: '.$inReplyTo;
        }

        $bodyText = $data['body_text'] ?? strip_tags($data['body_html'] ?? '');

        $alternative = "--boundary_relaticle\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            .$bodyText."\r\n\r\n"
            ."--boundary_relaticle\r\n"
            ."Content-Type: text/html; charset=UTF-8\r\n\r\n"
            .($data['body_html'] ?? '')."\r\n\r\n"
            .'--boundary_relaticle--';

        if ($inlineAttachments === [] && $fileAttachments === []) {
            return implode("\r\n", $headers)."\r\n\r\n".$alternative;
        }

        if ($fileAttachments === []) {
            return implode("\r\n", $headers)."\r\n\r\n".$this->mimeRelatedBody($alternative, $inlineAttachments);
        }

        $raw = implode("\r\n", $headers)."\r\n\r\n";
        $raw .= "--mixed_relaticle\r\n";

        if ($inlineAttachments === []) {
            $raw .= "Content-Type: multipart/alternative; boundary=\"boundary_relaticle\"\r\n\r\n";
            $raw .= $alternative."\r\n\r\n";
        } else {
            $raw .= "Content-Type: multipart/related; boundary=\"related_relaticle\"\r\n\r\n";
            $raw .= $this->mimeRelatedBody($alternative, $inlineAttachments)."\r\n\r\n";
        }

        foreach ($fileAttachments as $attachment) {
            $raw .= $this->mimeAttachmentPart('mixed_relaticle', $attachment, inline: false);
        }

        return $raw.'--mixed_relaticle--';
    }

    private function rootContentTypeHeader(bool $hasInline, bool $hasFiles): string
    {
        if ($hasFiles) {
            return 'Content-Type: multipart/mixed; boundary="mixed_relaticle"';
        }

        if ($hasInline) {
            return 'Content-Type: multipart/related; boundary="related_relaticle"';
        }

        return 'Content-Type: multipart/alternative; boundary="boundary_relaticle"';
    }

    /**
     * @param  array<int, array{filename: string, mime_type: string, content: string, is_inline?: bool, content_id?: ?string}>  $inlineAttachments
     */
    private function mimeRelatedBody(string $alternative, array $inlineAttachments): string
    {
        $raw = "--related_relaticle\r\n";
        $raw .= "Content-Type: multipart/alternative; boundary=\"boundary_relaticle\"\r\n\r\n";
        $raw .= $alternative."\r\n\r\n";

        foreach ($inlineAttachments as $attachment) {
            $raw .= $this->mimeAttachmentPart('related_relaticle', $attachment, inline: true);
        }

        return $raw.'--related_relaticle--';
    }

    /**
     * @param  array{filename: string, mime_type: string, content: string, is_inline?: bool, content_id?: ?string}  $attachment
     */
    private function mimeAttachmentPart(string $boundary, array $attachment, bool $inline): string
    {
        $filename = $this->sanitizeAttachmentFilename($attachment['filename']);

        $part = "--{$boundary}\r\n";
        $part .= 'Content-Type: '.$attachment['mime_type'].'; name="'.$filename."\"\r\n";
        $part .= "Content-Transfer-Encoding: base64\r\n";

        if ($inline) {
            $contentId = $this->sanitizeContentId((string) ($attachment['content_id'] ?? ''));
            $part .= 'Content-ID: <'.$contentId.">\r\n";
            $part .= 'Content-Disposition: inline; filename="'.$filename."\"\r\n\r\n";
        } else {
            $part .= 'Content-Disposition: attachment; filename="'.$filename."\"\r\n\r\n";
        }

        return $part.chunk_split(base64_encode($attachment['content']))."\r\n";
    }

    /**
     * Strip characters that could break out of the quoted filename and inject
     * MIME headers.
     */
    private function sanitizeAttachmentFilename(string $filename): string
    {
        return str_replace(['"', '\\', "\r", "\n"], '', $filename);
    }

    private function sanitizeContentId(string $contentId): string
    {
        return str_replace(['"', '\\', "\r", "\n", '<', '>'], '', $contentId);
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

    /**
     * Map Gmail's free, native inbox-category labels to our classification
     * vocabulary so we skip a paid LLM call for the high-volume noise buckets.
     *
     * Only high-confidence consumer categories are mapped. CATEGORY_UPDATES
     * (receipts, statements, confirmations) is deliberately left unmapped,
     * because it frequently hides Invoice/Scheduling/Support mail that only AI
     * resolves, as is an inbox-only message with no category at all.
     *
     * @param  array<int, string>  $labelIds
     */
    private function resolveProviderCategory(array $labelIds): ?EmailCategory
    {
        return match (true) {
            in_array('CATEGORY_PROMOTIONS', $labelIds, true) => EmailCategory::Marketing,
            in_array('CATEGORY_PERSONAL', $labelIds, true) => EmailCategory::Personal,
            in_array('CATEGORY_SOCIAL', $labelIds, true) => EmailCategory::Other,
            in_array('CATEGORY_FORUMS', $labelIds, true) => EmailCategory::Other,
            default => null,
        };
    }

    /**
     * Download an attachment binary from the Gmail API.
     * Returns the raw decoded bytes.
     */
    public function downloadAttachment(string $messageId, string $attachmentId): string
    {
        $part = $this->gmail->users_messages_attachments->get('me', $messageId, $attachmentId);

        return base64_decode(strtr($part->getData(), '-_', '+/'));
    }

    /**
     * Recursively walk MIME parts and collect attachment metadata.
     * The current part is inspected, not only its children, because an
     * attachment-only message puts the file on the root payload.
     * Covers both file attachments (Content-Disposition: attachment) and
     * inline images (Content-Disposition: inline with Content-ID).
     *
     * @param  array<string, true>  $referencedContentIds
     * @return array<int, array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, attachment_id: string|null, inline_data: string|null, is_inline: bool}>
     */
    private function extractAttachments(MessagePart $payload, array $referencedContentIds): array
    {
        $attachments = [];

        $partHeaders = collect($payload->getHeaders())
            ->keyBy(fn (MessagePartHeader $header): string => strtolower((string) $header->getName()));

        $disposition = $partHeaders->get('content-disposition')?->getValue() ?? '';
        $filename = $payload->getFilename();

        $contentId = $partHeaders->get('content-id')?->getValue();
        if ($contentId !== null) {
            $contentId = trim($contentId, '<>');
        }

        $mimeType = (string) $payload->getMimeType();
        $lowerDisposition = strtolower($disposition);
        $isInline = $contentId !== null && (
            str_starts_with($lowerDisposition, 'inline') ||
            isset($referencedContentIds[mb_strtolower($contentId)])
        );

        $isMultipart = str_starts_with(mb_strtolower($mimeType), 'multipart/');

        if (! $isMultipart && (filled($filename) || str_starts_with($lowerDisposition, 'attachment') || $isInline)) {
            $body = $payload->getBody();
            // getAttachmentId() returns empty string when not present (large attachments have it set)
            $gmailAttachmentId = $body->getAttachmentId();
            $attachmentId = filled($gmailAttachmentId) ? $gmailAttachmentId : null;

            $attachments[] = [
                'filename' => filled($filename) ? $filename : null,
                'mime_type' => $mimeType,
                'size' => $body->getSize(),
                'content_id' => $contentId,
                'attachment_id' => $attachmentId,
                // For small attachments (<25 KB) the binary is inlined; large ones have an attachment_id
                'inline_data' => $attachmentId === null ? ($body->getData() ?: null) : null,
                'is_inline' => $isInline,
            ];
        }

        foreach ($payload->getParts() as $part) {
            $attachments = array_merge($attachments, $this->extractAttachments($part, $referencedContentIds));
        }

        return $attachments;
    }

    /**
     * @return array<string, true>
     */
    private function extractReferencedContentIds(string $body): array
    {
        preg_match_all('/cid:([^"\'\s>)]+)/i', $body, $matches);

        $contentIds = [];

        foreach ($matches[1] as $contentId) {
            $contentIds[mb_strtolower(trim(rawurldecode($contentId), '<>'))] = true;
        }

        return $contentIds;
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

        if ($parts === false) {
            return [];
        }

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

    /**
     * @return Collection<string, MessagePartHeader>
     */
    private function indexHeaders(MessagePart $payload): Collection
    {
        /** @var array<int, MessagePartHeader> $headers */
        $headers = $payload->getHeaders();

        return collect($headers)
            ->keyBy(fn (MessagePartHeader $header): string => strtolower((string) $header->getName()));
    }

    /**
     * @param  array<int, Message>|null  $messages
     * @return Collection<int, string>
     */
    private function pluckMessageIds(?array $messages): Collection
    {
        return collect($messages ?? [])
            ->map(fn (Message $message): string => $message->getId());
    }
}
