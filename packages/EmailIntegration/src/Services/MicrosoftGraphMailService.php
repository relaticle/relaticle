<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use JsonException;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailBackfillPage;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Exceptions\MailHistoryExpired;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;
use RuntimeException;

final class MicrosoftGraphMailService implements MailServiceInterface
{
    /** Graph message delta is per-folder. These two cover inbound and outbound sync. */
    private const array DELTA_FOLDERS = ['inbox', 'sentitems'];

    private const string RECONCILIATION_PROPERTY_ID = 'String {00020329-0000-0000-C000-000000000046} Name RelaticleMessageId';

    /**
     * @var array<string, EmailFolder>
     */
    private const array WELL_KNOWN_FOLDERS = [
        'inbox' => EmailFolder::Inbox,
        'drafts' => EmailFolder::Drafts,
        'sentitems' => EmailFolder::Sent,
    ];

    /**
     * @var array<string, EmailFolder>|null Cached parentFolderId => EmailFolder map per instance.
     */
    private ?array $folderCache = null;

    public function __construct(
        private readonly ConnectedAccount $account,
        private readonly MicrosoftGraphClientFactory $clientFactory,
    ) {}

    public function fetchDelta(string $cursor): MailDeltaResult
    {
        $cursors = $this->decodeCursor($cursor);
        $http = $this->clientFactory->make($this->account);

        $messageIds = [];
        // id => isRead, last-write-wins. The same id can appear on several delta pages
        // with isRead flipping; keying by id keeps the LATEST state so a message never
        // lands in both the read and unread lists (which the sync job would then apply
        // in an order-dependent way).
        $readState = [];
        $newCursors = [];

        foreach (self::DELTA_FOLDERS as $folder) {
            $folderCursor = $cursors[$folder];
            $url = $folderCursor;
            $deltaLink = $folderCursor;

            do {
                $response = $this->getDeltaPage($http, $url);

                foreach ($response['value'] ?? [] as $message) {
                    // Graph delta includes tombstones for deleted messages; they carry no
                    // fetchable payload, so dispatching a StoreEmailJob would just 404.
                    if (isset($message['@removed'])) {
                        continue;
                    }

                    $id = (string) $message['id'];
                    $messageIds[] = $id;

                    if (array_key_exists('isRead', $message)) {
                        $readState[$id] = $message['isRead'] === true;
                    }
                }

                $nextLink = $response['@odata.nextLink'] ?? null;
                $deltaLink = $response['@odata.deltaLink'] ?? $deltaLink;
                $url = is_string($nextLink) && $nextLink !== '' ? $nextLink : null;
            } while ($url !== null);

            $newCursors[$folder] = (string) $deltaLink;
        }

        $readMessageIds = [];
        $unreadMessageIds = [];
        foreach ($readState as $id => $isRead) {
            if ($isRead) {
                $readMessageIds[] = $id;
            } else {
                $unreadMessageIds[] = $id;
            }
        }

        return new MailDeltaResult(
            messageIds: collect($messageIds)->unique()->values(),
            readMessageIds: collect($readMessageIds)->values(),
            newCursor: $this->encodeCursor($newCursors),
            unreadMessageIds: collect($unreadMessageIds)->values(),
        );
    }

    public function fetchMessage(string $providerMessageId): FetchedEmailData
    {
        $message = $this->clientFactory->make($this->account)
            ->get("/me/messages/{$providerMessageId}", [
                '$select' => 'id,internetMessageId,conversationId,subject,bodyPreview,receivedDateTime,sentDateTime,isRead,hasAttachments,parentFolderId,from,toRecipients,ccRecipients,bccRecipients,body',
                // Pull attachment metadata (not bytes) alongside the message so has-attachment
                // rows expose a downloadable list; bytes are fetched on demand via downloadAttachment().
                '$expand' => 'attachments($select=id,name,contentType,size,isInline,contentId)',
            ])
            ->throw()
            ->json();

        $participants = [
            ...$this->mapAddresses('from', [$message['from']['emailAddress'] ?? null]),
            ...$this->mapAddresses('to', array_column($message['toRecipients'] ?? [], 'emailAddress')),
            ...$this->mapAddresses('cc', array_column($message['ccRecipients'] ?? [], 'emailAddress')),
            ...$this->mapAddresses('bcc', array_column($message['bccRecipients'] ?? [], 'emailAddress')),
        ];

        $sentAt = Date::parse((string) ($message['receivedDateTime'] ?? $message['sentDateTime'] ?? now()->toIso8601String()));
        $folder = $this->resolveFolder((string) ($message['parentFolderId'] ?? ''));
        $isOutbound = $folder === EmailFolder::Sent;

        $bodyHtml = (($message['body']['contentType'] ?? '') === 'html') ? (string) ($message['body']['content'] ?? '') : null;
        $bodyText = (($message['body']['contentType'] ?? '') === 'text') ? (string) ($message['body']['content'] ?? '') : null;

        return new FetchedEmailData(
            providerMessageId: (string) $message['id'],
            rfcMessageId: $message['internetMessageId'] ?? null,
            threadId: (string) ($message['conversationId'] ?? ''),
            inReplyTo: null,
            subject: $message['subject'] ?? null,
            snippet: mb_substr(strip_tags((string) ($message['bodyPreview'] ?? '')), 0, 255),
            sentAt: $sentAt,
            direction: $isOutbound ? EmailDirection::OUTBOUND : EmailDirection::INBOUND,
            folder: $folder,
            hasAttachments: (bool) ($message['hasAttachments'] ?? false),
            isRead: (bool) ($message['isRead'] ?? false),
            bodyText: $bodyText,
            bodyHtml: $bodyHtml,
            participants: $participants,
            attachments: $this->mapInboundAttachments($message['attachments'] ?? []),
        );
    }

    /**
     * Map Graph attachment metadata to our attachment shape. Bytes are intentionally
     * not fetched here; provider_attachment_id lets downloadAttachment() pull them
     * on demand, mirroring how large Gmail attachments are handled.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<int, array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, attachment_id: string|null, inline_data: string|null}>
     */
    private function mapInboundAttachments(array $attachments): array
    {
        return array_map(fn (array $attachment): array => [
            'filename' => isset($attachment['name']) ? (string) $attachment['name'] : null,
            'mime_type' => isset($attachment['contentType']) ? (string) $attachment['contentType'] : null,
            'size' => (int) ($attachment['size'] ?? 0),
            'content_id' => isset($attachment['contentId']) ? (string) $attachment['contentId'] : null,
            'attachment_id' => isset($attachment['id']) ? (string) $attachment['id'] : null,
            'inline_data' => null,
        ], $attachments);
    }

    public function initialBackfill(?int $daysBack = null, ?string $pageToken = null): MailBackfillPage
    {
        $state = $this->backfillState($pageToken, $daysBack);
        $http = $this->clientFactory->make($this->account);
        $response = $this->getDeltaPage($http, $state['url']);

        $messageIds = [];

        foreach ($response['value'] ?? [] as $message) {
            if (isset($message['@removed'])) {
                continue;
            }

            $messageIds[] = (string) $message['id'];
        }

        $ids = collect($messageIds)->unique()->values();
        $nextLink = $response['@odata.nextLink'] ?? null;
        $deltaLink = $response['@odata.deltaLink'] ?? null;

        if (is_string($nextLink) && $nextLink !== '') {
            return new MailBackfillPage(
                messageIds: $ids,
                nextPageToken: $this->encodeBackfillState([
                    ...$state,
                    'url' => $nextLink,
                ]),
                cursor: null,
            );
        }

        throw_unless(
            is_string($deltaLink) && $deltaLink !== '',
            RuntimeException::class,
            'Microsoft Graph delta page included neither nextLink nor deltaLink.',
        );

        $cursors = $state['cursors'];
        $cursors[$state['folder']] = $deltaLink;
        $nextFolder = $this->nextDeltaFolder($state['folder']);

        if ($nextFolder !== null) {
            return new MailBackfillPage(
                messageIds: $ids,
                nextPageToken: $this->encodeBackfillState([
                    'folder' => $nextFolder,
                    'url' => $this->folderDeltaUrl($nextFolder, $state['daysBack']),
                    'cursors' => $cursors,
                    'daysBack' => $state['daysBack'],
                ]),
                cursor: null,
            );
        }

        return new MailBackfillPage(
            messageIds: $ids,
            nextPageToken: null,
            cursor: $this->encodeCursor($cursors),
        );
    }

    /**
     * Send a new email, or reply when `in_reply_to` / `thread_id` resolve to a
     * message in this mailbox. Graph conversation ids are mailbox-local.
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
        $message = $this->graphMessagePayload($data);
        $http = $this->clientFactory->make($this->account);
        $replyTo = $this->resolveReplyTarget($data);
        $synthetic = (string) Str::ulid();
        $threadId = "ms-pending-thread-{$synthetic}";

        if ($replyTo === null) {
            $http->post('/me/sendMail', ['message' => $message, 'saveToSentItems' => true])
                ->throw();
        } else {
            // /reply stamps In-Reply-To, References, and conversationId. sendMail cannot.
            $http->post('/me/messages/'.rawurlencode($replyTo['id']).'/reply', ['message' => $message])
                ->throw();

            $threadId = $replyTo['conversationId'] !== ''
                ? $replyTo['conversationId']
                : $threadId;
        }

        // Graph send/reply returns 202 with no body. Synthesize the message id;
        // the next delta sync picks up the canonical Graph id + internetMessageId.
        return [
            'provider_message_id' => "ms-pending-{$synthetic}",
            'thread_id' => $threadId,
            'rfc_message_id' => $data['rfc_message_id'] ?? "<{$synthetic}@graph.microsoft.com>",
        ];
    }

    /**
     * @param array{
     *     subject: string,
     *     body_html: string,
     *     to: array<int, array{email: string, name: ?string}>,
     *     cc?: array<int, array{email: string, name: ?string}>,
     *     bcc?: array<int, array{email: string, name: ?string}>,
     *     rfc_message_id?: string,
     *     attachments?: array<int, array{filename: string, mime_type: string, content: string, is_inline?: bool, content_id?: ?string}>,
     * } $data
     * @return array<string, mixed>
     */
    private function graphMessagePayload(array $data): array
    {
        $message = [
            'subject' => $data['subject'],
            'body' => [
                'contentType' => 'HTML',
                'content' => $data['body_html'],
            ],
            'toRecipients' => $this->formatRecipients($data['to']),
            'ccRecipients' => $this->formatRecipients($data['cc'] ?? []),
            'bccRecipients' => $this->formatRecipients($data['bcc'] ?? []),
        ];

        if (($data['attachments'] ?? []) !== []) {
            $message['attachments'] = array_map(function (array $attachment): array {
                $payload = [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'name' => $attachment['filename'],
                    'contentType' => $attachment['mime_type'],
                    'contentBytes' => base64_encode($attachment['content']),
                ];

                if (($attachment['is_inline'] ?? false) === true) {
                    $payload['isInline'] = true;

                    if (filled($attachment['content_id'] ?? null)) {
                        $payload['contentId'] = $attachment['content_id'];
                    }
                }

                return $payload;
            }, $data['attachments']);
        }

        if (isset($data['rfc_message_id'])) {
            $message['singleValueExtendedProperties'] = [[
                'id' => self::RECONCILIATION_PROPERTY_ID,
                'value' => $data['rfc_message_id'],
            ]];
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{id: string, conversationId: string}|null
     */
    private function resolveReplyTarget(array $data): ?array
    {
        $inReplyTo = $data['in_reply_to'] ?? null;

        if (is_string($inReplyTo) && $inReplyTo !== '') {
            $match = $this->findMessageByFilter("internetMessageId eq '{$this->escapeODataString($inReplyTo)}'");

            if ($match !== null) {
                return $match;
            }
        }

        $threadId = $data['thread_id'] ?? null;

        if (! is_string($threadId) || $threadId === '' || str_starts_with($threadId, 'ms-pending-')) {
            return null;
        }

        return $this->findMessageByFilter("conversationId eq '{$this->escapeODataString($threadId)}'");
    }

    /**
     * @return array{id: string, conversationId: string}|null
     */
    private function findMessageByFilter(string $filter): ?array
    {
        $message = $this->clientFactory->make($this->account)
            ->get('/me/messages', [
                '$filter' => $filter,
                '$select' => 'id,conversationId',
                '$top' => 1,
            ])
            ->throw()
            ->json('value.0');

        if (! is_array($message) || ! isset($message['id'])) {
            return null;
        }

        return [
            'id' => (string) $message['id'],
            'conversationId' => (string) ($message['conversationId'] ?? ''),
        ];
    }

    private function escapeODataString(string $value): string
    {
        return str_replace("'", "''", $value);
    }

    public function findSentMessage(string $rfcMessageId): ?array
    {
        $escaped = $this->escapeODataString($rfcMessageId);

        $message = $this->clientFactory->make($this->account)
            ->get('/me/messages', [
                '$filter' => "singleValueExtendedProperties/Any(ep: ep/id eq '".self::RECONCILIATION_PROPERTY_ID."' and ep/value eq '{$escaped}')",
                '$select' => 'id,conversationId,internetMessageId',
                '$top' => 1,
            ])
            ->throw()
            ->json('value.0');

        if (! is_array($message) || ! isset($message['id'])) {
            return null;
        }

        return [
            'provider_message_id' => (string) $message['id'],
            'thread_id' => (string) ($message['conversationId'] ?? ''),
            'rfc_message_id' => (string) ($message['internetMessageId'] ?? $rfcMessageId),
        ];
    }

    public function downloadAttachment(string $providerMessageId, string $providerAttachmentId): string
    {
        $attachment = $this->clientFactory->make($this->account)
            ->get("/me/messages/{$providerMessageId}/attachments/{$providerAttachmentId}")
            ->throw()
            ->json();

        // Only fileAttachment carries inline bytes; itemAttachment / referenceAttachment
        // have no contentBytes and cannot be streamed as a binary download.
        $contentBytes = $attachment['contentBytes'] ?? null;

        throw_if(! is_string($contentBytes) || $contentBytes === '', RuntimeException::class, 'Attachment is not available for download.');

        return (string) base64_decode($contentBytes, strict: true);
    }

    /**
     * @param  array<int, array{email: string, name: string|null}>  $recipients
     * @return array<int, array{emailAddress: array<string, string>}>
     */
    private function formatRecipients(array $recipients): array
    {
        return array_map(static fn (array $r): array => [
            'emailAddress' => array_filter([
                'address' => $r['email'],
                'name' => $r['name'] ?? null,
            ], static fn (?string $v): bool => $v !== null && $v !== ''),
        ], $recipients);
    }

    /**
     * @param  array<int, array{address?: string|null, name?: string|null}|null>  $addresses
     * @return array<int, array{email_address: string, name: string|null, role: string}>
     */
    private function mapAddresses(string $role, array $addresses): array
    {
        $out = [];

        foreach ($addresses as $address) {
            if ($address === null) {
                continue;
            }
            $email = $address['address'] ?? null;
            if (blank($email)) {
                continue;
            }
            $out[] = [
                'role' => $role,
                'email_address' => strtolower($email),
                'name' => $address['name'] ?? null,
            ];
        }

        return $out;
    }

    private function resolveFolder(string $parentFolderId): EmailFolder
    {
        $this->folderCache ??= $this->wellKnownFolderIds();

        return $this->folderCache[$parentFolderId] ?? EmailFolder::Archive;
    }

    /**
     * @return array<string, EmailFolder>
     */
    private function wellKnownFolderIds(): array
    {
        // Graph displayName is localized (Entwürfe). Well-known path names are not.
        $http = $this->clientFactory->make($this->account);
        $ids = [];

        foreach (self::WELL_KNOWN_FOLDERS as $wellKnownName => $folder) {
            $id = $http->get("/me/mailFolders/{$wellKnownName}", ['$select' => 'id'])
                ->throw()
                ->json('id');

            if (is_string($id) && $id !== '') {
                $ids[$id] = $folder;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function getDeltaPage(PendingRequest $http, string $url): array
    {
        try {
            $response = $http->get($url)->throw()->json();
        } catch (RequestException $e) {
            if ($e->response->status() === 410) {
                throw MailHistoryExpired::forAccount((string) $this->account->getKey());
            }

            throw $e;
        }

        return is_array($response) ? $response : [];
    }

    /**
     * @return array{folder: string, url: string, cursors: array<string, string>, daysBack: int|null}
     */
    private function backfillState(?string $pageToken, ?int $daysBack): array
    {
        if ($pageToken === null || $pageToken === '') {
            $folder = self::DELTA_FOLDERS[0];

            return [
                'folder' => $folder,
                'url' => $this->folderDeltaUrl($folder, $daysBack),
                'cursors' => [],
                'daysBack' => $daysBack,
            ];
        }

        try {
            $decoded = json_decode($pageToken, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Microsoft Graph mail backfill page token is invalid.');
        }

        throw_unless(is_array($decoded), RuntimeException::class, 'Microsoft Graph mail backfill page token is invalid.');

        $folder = $decoded['folder'] ?? null;
        $url = $decoded['url'] ?? null;
        $cursors = $decoded['cursors'] ?? null;
        $tokenDaysBack = $decoded['daysBack'] ?? null;

        throw_if(! is_string($folder) || ! in_array($folder, self::DELTA_FOLDERS, true), RuntimeException::class, 'Microsoft Graph mail backfill page token is invalid.');
        throw_if(! is_string($url) || $url === '', RuntimeException::class, 'Microsoft Graph mail backfill page token is invalid.');
        throw_unless(is_array($cursors), RuntimeException::class, 'Microsoft Graph mail backfill page token is invalid.');
        throw_if($tokenDaysBack !== null && ! is_int($tokenDaysBack), RuntimeException::class, 'Microsoft Graph mail backfill page token is invalid.');

        $stringCursors = [];

        foreach ($cursors as $key => $value) {
            throw_if(! is_string($key) || ! is_string($value) || $value === '', RuntimeException::class, 'Microsoft Graph mail backfill page token is invalid.');

            $stringCursors[$key] = $value;
        }

        return [
            'folder' => $folder,
            'url' => $url,
            'cursors' => $stringCursors,
            'daysBack' => $tokenDaysBack,
        ];
    }

    /**
     * @param  array{folder: string, url: string, cursors: array<string, string>, daysBack: int|null}  $state
     */
    private function encodeBackfillState(array $state): string
    {
        return json_encode([
            'v' => 1,
            ...$state,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, string>  $cursors
     */
    private function encodeCursor(array $cursors): string
    {
        $ordered = [];

        foreach (self::DELTA_FOLDERS as $folder) {
            $folderCursor = $cursors[$folder] ?? null;

            throw_unless(
                is_string($folderCursor) && $folderCursor !== '',
                RuntimeException::class,
                'Microsoft Graph mail backfill finished without a delta cursor for every folder.',
            );

            $ordered[$folder] = $folderCursor;
        }

        return json_encode([
            'v' => 1,
            'cursors' => $ordered,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, string>
     */
    private function decodeCursor(string $cursor): array
    {
        try {
            $decoded = json_decode($cursor, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MailHistoryExpired::forAccount((string) $this->account->getKey());
        }

        if (! is_array($decoded)) {
            throw MailHistoryExpired::forAccount((string) $this->account->getKey());
        }

        $cursors = $decoded['cursors'] ?? null;

        if (! is_array($cursors)) {
            throw MailHistoryExpired::forAccount((string) $this->account->getKey());
        }

        $decodedCursors = [];

        foreach (self::DELTA_FOLDERS as $folder) {
            $folderCursor = $cursors[$folder] ?? null;

            if (! is_string($folderCursor) || $folderCursor === '') {
                throw MailHistoryExpired::forAccount((string) $this->account->getKey());
            }

            $decodedCursors[$folder] = $folderCursor;
        }

        return $decodedCursors;
    }

    private function folderDeltaUrl(string $folder, ?int $daysBack): string
    {
        $path = "/me/mailFolders/{$folder}/messages/delta";

        if ($daysBack === null || $daysBack <= 0) {
            return $path;
        }

        $afterIso = now()->subDays($daysBack)->toIso8601String();

        return $path.'?$filter='.rawurlencode("receivedDateTime ge {$afterIso}");
    }

    private function nextDeltaFolder(string $current): ?string
    {
        $index = array_search($current, self::DELTA_FOLDERS, true);

        if ($index === false) {
            return null;
        }

        return self::DELTA_FOLDERS[$index + 1] ?? null;
    }
}
