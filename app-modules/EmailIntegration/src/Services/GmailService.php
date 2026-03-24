<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Google\Service\Gmail\MessagePart;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class GmailService
{
    private Gmail $gmail;

    public function __construct(private ConnectedAccount $account)
    {
        $client = new GoogleClient;
        $client->setClientId(config('services.gmail.client_id'));
        $client->setClientSecret(config('services.gmail.client_secret'));
        $client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->token_expires_at?->diffInSeconds(now()),
        ]);

        if ($client->isAccessTokenExpired()) {
            $newToken = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);

            $account->update([
                'access_token' => $newToken['access_token'],
                'token_expires_at' => now()->addSeconds($newToken['expires_in']),
            ]);
        }

        $this->gmail = new Gmail($client);
    }

    /**
     * Fetch messages newer than the given historyId (incremental sync).
     * Returns the new historyId cursor to persist.
     */
    public function fetchDelta(string $historyId): array
    {
        $history = $this->gmail->users_history->listUsersHistory('me', [
            'startHistoryId' => $historyId,
            'historyTypes' => ['messageAdded'],
        ]);

        $messageIds = collect($history->getHistory() ?? [])
            ->flatMap(fn ($item) => $item->getMessagesAdded() ?? [])
            ->map(fn ($msg) => $msg->getMessage()->getId())
            ->unique()
            ->values();

        return [
            'message_ids' => $messageIds,
            'new_history_id' => $history->getHistoryId() ?? $historyId,
        ];
    }

    /**
     * Fetch full message details by ID and map to our Email schema.
     */
    public function fetchMessage(string $messageId): array
    {
        $message = $this->gmail->users_messages->get('me', $messageId, ['format' => 'full']);
        $headers = collect($message->getPayload()->getHeaders())
            ->keyBy(fn ($h) => strtolower($h->getName()));

        return [
            'provider_message_id' => $message->getId(),
            'rfc_message_id' => $headers->get('message-id')?->getValue(),
            'thread_id' => $message->getThreadId(),
            'in_reply_to' => $headers->get('in-reply-to')?->getValue(),
            'subject' => $headers->get('subject')?->getValue(),
            'snippet' => mb_substr(strip_tags((string) $message->getSnippet()), 0, 255),
            'sent_at' => now()->setTimestamp((int) ($message->getInternalDate() / 1000)),
            'direction' => $this->resolveDirection($headers),
            'folder' => $this->resolveFolder($message->getLabelIds() ?? []),
            'has_attachments' => $this->hasAttachments($message->getPayload()),
            'body_text' => $this->extractBody($message->getPayload(), 'text/plain'),
            'body_html' => $this->extractBody($message->getPayload(), 'text/html'),
            'participants' => $this->extractParticipants($headers),
        ];
    }

    /**
     * Get initial historyId and list of message IDs for backfill.
     */
    public function fetchInitialMessages(int $daysBack = 90): array
    {
        $after = now()->subDays($daysBack)->timestamp;

        $response = $this->gmail->users_messages->listUsersMessages('me', [
            'q' => "after:{$after}",
            'maxResults' => 500,
        ]);

        $messageIds = collect($response->getMessages() ?? [])
            ->map(fn ($m) => $m->getId());

        $profile = $this->gmail->users->getProfile('me');
        $historyId = $profile->getHistoryId();

        return [
            'message_ids' => $messageIds,
            'history_id' => $historyId,
        ];
    }

    private function resolveDirection($headers): string
    {
        $fromHeader = $headers->get('from')?->getValue() ?? '';
        $accountEmail = strtolower($this->account->email_address);

        // Extract email address from "Name <email>" format
        if (preg_match('/<([^>]+)>/', $fromHeader, $matches)) {
            $fromEmail = strtolower(trim($matches[1]));
        } else {
            $fromEmail = strtolower(trim($fromHeader));
        }

        return $fromEmail === $accountEmail ? 'outbound' : 'inbound';
    }

    private function resolveFolder(array $labelIds): string
    {
        if (in_array('SENT', $labelIds)) {
            return 'sent';
        }

        if (in_array('DRAFT', $labelIds)) {
            return 'drafts';
        }

        if (in_array('INBOX', $labelIds)) {
            return 'inbox';
        }

        return 'archive';
    }

    private function hasAttachments(MessagePart $payload): bool
    {
        $parts = $payload->getParts() ?? [];

        foreach ($parts as $part) {
            $disposition = collect($part->getHeaders() ?? [])
                ->keyBy(fn ($h) => strtolower($h->getName()))
                ->get('content-disposition')?->getValue() ?? '';

            if (str_starts_with(strtolower($disposition), 'attachment')) {
                return true;
            }

            if ($part->getParts() && $this->hasAttachments($part)) {
                return true;
            }
        }

        return false;
    }

    private function extractBody(MessagePart $payload, string $mimeType): ?string
    {
        // Direct match on top-level payload
        if ($payload->getMimeType() === $mimeType) {
            $data = $payload->getBody()?->getData();
            if ($data) {
                return base64_decode(strtr($data, '-_', '+/'));
            }
        }

        // Recurse through MIME parts
        foreach ($payload->getParts() ?? [] as $part) {
            $result = $this->extractBody($part, $mimeType);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function extractParticipants($headers): array
    {
        $participants = [];

        foreach (['from', 'to', 'cc', 'bcc'] as $role) {
            $value = $headers->get($role)?->getValue();
            if (! $value) {
                continue;
            }

            // Parse RFC 5322 address list: "Name <email>, Name2 <email2>, email3"
            foreach ($this->parseAddressList($value) as $address) {
                $participants[] = array_merge(['role' => $role], $address);
            }
        }

        return $participants;
    }

    private function parseAddressList(string $raw): array
    {
        $addresses = [];

        // Split on comma, but not commas inside angle brackets or quoted strings
        $parts = preg_split('/,(?![^<>]*>)/', $raw);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            if (preg_match('/^(.*?)\s*<([^>]+)>$/', $part, $matches)) {
                // "Display Name <email@example.com>"
                $addresses[] = [
                    'name' => trim($matches[1], ' "\''),
                    'email_address' => strtolower(trim($matches[2])),
                ];
            } elseif (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                // Plain "email@example.com"
                $addresses[] = [
                    'name' => null,
                    'email_address' => strtolower($part),
                ];
            }
        }

        return $addresses;
    }
}
