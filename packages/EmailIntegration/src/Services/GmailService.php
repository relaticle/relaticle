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
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\Exceptions\MailHistoryExpired;

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
        /** @var array<int, string> $readMessageIds */
        $readMessageIds = [];
        /** @var array<int, string> $unreadMessageIds */
        $unreadMessageIds = [];

        $newCursor = $cursor;
        $pageToken = null;

        // The History API paginates: a single page may not contain all changes since
        // the cursor. Follow getNextPageToken() until exhausted, otherwise multi-page
        // history is dropped and newCursor jumps ahead of unfetched messages (data loss).
        do {
            $params = [
                'startHistoryId' => $cursor,
                'historyTypes' => ['messageAdded', 'labelsRemoved', 'labelsAdded'],
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
                        $id = $change->getMessage()->getId();
                        if (! in_array($id, $readMessageIds, strict: true)) {
                            $readMessageIds[] = $id;
                        }
                    }
                }

                // Track messages where the UNREAD label was re-added (marked unread again)
                foreach ($item->getLabelsAdded() ?? [] as $change) {
                    if (in_array('UNREAD', $change->getLabelIds() ?? [], strict: true)) {
                        $id = $change->getMessage()->getId();
                        if (! in_array($id, $unreadMessageIds, strict: true)) {
                            $unreadMessageIds[] = $id;
                        }
                    }
                }
            }

            // historyId is the same on every page (= head of history at request time);
            // keep the latest non-null so the cursor advances only past fully-paged history.
            $newCursor = (string) ($history->getHistoryId() ?? $newCursor);

            $pageToken = $history->getNextPageToken();
        } while ($pageToken !== null && $pageToken !== '');

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
        ];

        if ($daysBack !== null && $daysBack > 0) {
            $params['q'] = 'after:'.now()->subDays($daysBack)->timestamp;
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
     * Pass `in_reply_to` and `thread_id` in $data to send as a reply.
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
     * } $data
     * @return array{provider_message_id: string, thread_id: string, rfc_message_id: string}
     */
    public function sendMessage(array $data): array
    {
        $isReply = isset($data['in_reply_to']);
        $raw = $this->buildMimeMessage($data, $data['in_reply_to'] ?? null);

        $message = new Message;
        $message->setRaw(rtrim(strtr(base64_encode($raw), '+/', '-_'), '='));

        if ($isReply) {
            assert(isset($data['thread_id']), 'thread_id is required when in_reply_to is set');
            $message->setThreadId($data['thread_id']);
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

        $headers[] = 'Subject: =?UTF-8?B?'.base64_encode((string) $data['subject']).'?=';
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = $attachments === []
            ? 'Content-Type: multipart/alternative; boundary="boundary_relaticle"'
            : 'Content-Type: multipart/mixed; boundary="mixed_relaticle"';
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

        if ($attachments === []) {
            return implode("\r\n", $headers)."\r\n\r\n".$alternative;
        }

        // Attachments present: nest the alternative body inside a multipart/mixed
        // envelope, then append each file as a base64 attachment part.
        $raw = implode("\r\n", $headers)."\r\n\r\n";
        $raw .= "--mixed_relaticle\r\n";
        $raw .= "Content-Type: multipart/alternative; boundary=\"boundary_relaticle\"\r\n\r\n";
        $raw .= $alternative."\r\n\r\n";

        foreach ($attachments as $attachment) {
            $filename = $this->sanitizeAttachmentFilename($attachment['filename']);

            $raw .= "--mixed_relaticle\r\n";
            $raw .= 'Content-Type: '.$attachment['mime_type'].'; name="'.$filename."\"\r\n";
            $raw .= "Content-Transfer-Encoding: base64\r\n";
            $raw .= 'Content-Disposition: attachment; filename="'.$filename."\"\r\n\r\n";
            $raw .= chunk_split(base64_encode($attachment['content']))."\r\n";
        }

        return $raw.'--mixed_relaticle--';
    }

    /**
     * Strip characters that could break out of the quoted filename and inject
     * MIME headers.
     */
    private function sanitizeAttachmentFilename(string $filename): string
    {
        return str_replace(['"', '\\', "\r", "\n"], '', $filename);
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
     * Covers both file attachments (Content-Disposition: attachment) and
     * inline images (Content-Disposition: inline with Content-ID).
     *
     * @param  array<string, true>  $referencedContentIds
     * @return array<int, array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, attachment_id: string|null, inline_data: string|null, is_inline: bool}>
     */
    private function extractAttachments(MessagePart $payload, array $referencedContentIds): array
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

            $mimeType = (string) $part->getMimeType();
            $lowerDisposition = strtolower($disposition);
            $isInline = $contentId !== null && (
                str_starts_with($lowerDisposition, 'inline') ||
                isset($referencedContentIds[mb_strtolower($contentId)])
            );

            if (filled($filename) || str_starts_with($lowerDisposition, 'attachment') || $isInline) {
                $body = $part->getBody();
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

            // Recurse into multipart containers (e.g. multipart/mixed, multipart/related)
            if ($part->getParts()) {
                $attachments = array_merge($attachments, $this->extractAttachments($part, $referencedContentIds));
            }
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
