<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;

final class MicrosoftGraphMailService implements MailServiceInterface
{
    private const string INBOX_DELTA = '/me/mailFolders/Inbox/messages/delta';

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
        $readMessageIds = [];
        $nextUrl = $cursor;
        $deltaLink = $cursor;

        do {
            $response = $http->get($nextUrl)->throw()->json();

            foreach ($response['value'] ?? [] as $message) {
                $id = (string) $message['id'];
                $messageIds[] = $id;

                if (($message['isRead'] ?? false) === true) {
                    $readMessageIds[] = $id;
                }
            }

            $nextUrl = $response['@odata.nextLink'] ?? null;
            $deltaLink = $response['@odata.deltaLink'] ?? $deltaLink;
        } while ($nextUrl !== null);

        return new MailDeltaResult(
            messageIds: collect($messageIds)->unique()->values(),
            readMessageIds: collect($readMessageIds)->unique()->values(),
            newCursor: (string) $deltaLink,
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

        $sentAt = Carbon::parse((string) ($message['receivedDateTime'] ?? $message['sentDateTime'] ?? now()->toIso8601String()));
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
            attachments: [],
        );
    }

    public function initialBackfill(int $daysBack): array
    {
        $http = $this->clientFactory->make($this->account);

        $afterIso = now()->subDays($daysBack)->toIso8601String();

        $messageIds = [];
        $deltaLink = '';
        $nextUrl = self::INBOX_DELTA.'?$filter='.rawurlencode("receivedDateTime ge {$afterIso}");

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
        $payload = [
            'message' => [
                'subject' => $data['subject'],
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $data['body_html'],
                ],
                'toRecipients' => $this->formatRecipients($data['to']),
                'ccRecipients' => $this->formatRecipients($data['cc'] ?? []),
                'bccRecipients' => $this->formatRecipients($data['bcc'] ?? []),
            ],
            'saveToSentItems' => true,
        ];

        $this->clientFactory->make($this->account)
            ->post('/me/sendMail', $payload)
            ->throw();

        // Graph /me/sendMail returns 202 with no body. Synthesize ids; the next
        // delta sync will pick up the canonical Graph id + internetMessageId.
        $synthetic = (string) Str::ulid();

        return [
            'provider_message_id' => "ms-pending-{$synthetic}",
            'thread_id' => "ms-pending-thread-{$synthetic}",
            'rfc_message_id' => "<{$synthetic}@graph.microsoft.com>",
        ];
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
            ], static fn ($v): bool => $v !== null && $v !== ''),
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
            if ($address === null || empty($address['address'])) {
                continue;
            }

            $out[] = [
                'role' => $role,
                'email_address' => strtolower((string) $address['address']),
                'name' => isset($address['name']) ? (string) $address['name'] : null,
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
