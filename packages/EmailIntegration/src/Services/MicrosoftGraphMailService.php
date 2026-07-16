<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;
use RuntimeException;

final class MicrosoftGraphMailService implements MailServiceInterface
{
    // All-folder delta stream. Unlike a per-folder endpoint (/me/mailFolders/Inbox/...),
    // this spans Inbox, SentItems, etc. in a single cursor, so Sent mail syncs too.
    // Folder/direction is derived per message from parentFolderId in fetchMessage().
    private const string MESSAGES_DELTA = '/me/messages/delta';

    /**
     * @var array<string, string>|null Cached folder id => lowercase displayName map per instance.
     */
    private ?array $folderCache = null;

    public function __construct(
        private readonly ConnectedAccount $account,
        private readonly MicrosoftGraphClientFactory $clientFactory,
    ) {}

    public function fetchDelta(string $cursor): MailDeltaResult
    {
        $http = $this->clientFactory->make($this->account);

        $messageIds = [];
        // id => isRead, last-write-wins. The same id can appear on several delta pages
        // with isRead flipping; keying by id keeps the LATEST state so a message never
        // lands in both the read and unread lists (which the sync job would then apply
        // in an order-dependent way).
        $readState = [];
        $nextUrl = $cursor;
        $deltaLink = $cursor;

        do {
            $response = $http->get($nextUrl)->throw()->json();

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

            $nextUrl = $response['@odata.nextLink'] ?? null;
            $deltaLink = $response['@odata.deltaLink'] ?? $deltaLink;
        } while ($nextUrl !== null);

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
            newCursor: (string) $deltaLink,
            unreadMessageIds: collect($unreadMessageIds)->values(),
        );
    }

    public function fetchMessage(string $providerMessageId): FetchedEmailData
    {
        $message = $this->clientFactory->make($this->account)
            ->get("/me/messages/{$providerMessageId}", [
                '$select' => 'id,internetMessageId,conversationId,subject,bodyPreview,receivedDateTime,sentDateTime,isRead,hasAttachments,parentFolderId,from,toRecipients,ccRecipients,bccRecipients,body',
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
            // NOTE: Azure attachment bytes are not fetched yet (would require a separate
            // /messages/{id}/attachments call). hasAttachments is still surfaced for the UI.
            attachments: [],
        );
    }

    public function initialBackfill(int $daysBack): array
    {
        $http = $this->clientFactory->make($this->account);

        $afterIso = now()->subDays($daysBack)->toIso8601String();

        $messageIds = [];
        $deltaLink = '';
        $nextUrl = self::MESSAGES_DELTA.'?$filter='.rawurlencode("receivedDateTime ge {$afterIso}");

        do {
            $response = $http->get($nextUrl)->throw()->json();

            foreach ($response['value'] ?? [] as $message) {
                $messageIds[] = (string) $message['id'];
            }

            $nextUrl = $response['@odata.nextLink'] ?? null;
            $deltaLink = (string) ($response['@odata.deltaLink'] ?? $deltaLink);
        } while ($nextUrl !== null);

        return [
            'message_ids' => collect($messageIds)->unique()->values(),
            'cursor' => $deltaLink,
        ];
    }

    public function sendMessage(array $data): array
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

        // Best-effort: ask Graph to use our Message-ID so a retry can reconcile via
        // findSentMessage(). Graph may override it on /me/sendMail, in which case
        // retry de-duplication degrades to the synthetic id below.
        if (isset($data['rfc_message_id'])) {
            $message['internetMessageId'] = $data['rfc_message_id'];
        }

        if (($data['attachments'] ?? []) !== []) {
            $message['attachments'] = array_map(fn (array $attachment): array => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment['filename'],
                'contentType' => $attachment['mime_type'],
                'contentBytes' => base64_encode($attachment['content']),
            ], $data['attachments']);
        }

        $this->clientFactory->make($this->account)
            ->post('/me/sendMail', ['message' => $message, 'saveToSentItems' => true])
            ->throw();

        // Graph /me/sendMail returns 202 with no body. Synthesize ids; the next
        // delta sync will pick up the canonical Graph id + internetMessageId.
        $synthetic = (string) Str::ulid();

        return [
            'provider_message_id' => "ms-pending-{$synthetic}",
            'thread_id' => "ms-pending-thread-{$synthetic}",
            'rfc_message_id' => $data['rfc_message_id'] ?? "<{$synthetic}@graph.microsoft.com>",
        ];
    }

    public function findSentMessage(string $rfcMessageId): ?array
    {
        $escaped = str_replace("'", "''", $rfcMessageId);

        $message = $this->clientFactory->make($this->account)
            ->get('/me/messages', [
                '$filter' => "internetMessageId eq '{$escaped}'",
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
        if ($this->folderCache === null) {
            $cache = [];
            $folders = $this->clientFactory->make($this->account)
                ->get('/me/mailFolders', ['$select' => 'id,displayName'])
                ->throw()
                ->json('value') ?? [];

            foreach ($folders as $folder) {
                $cache[(string) $folder['id']] = strtolower((string) $folder['displayName']);
            }

            $this->folderCache = $cache;
        }

        return match ($this->folderCache[$parentFolderId] ?? '') {
            'sent items', 'sent' => EmailFolder::Sent,
            'drafts' => EmailFolder::Drafts,
            'inbox' => EmailFolder::Inbox,
            default => EmailFolder::Archive,
        };
    }
}
