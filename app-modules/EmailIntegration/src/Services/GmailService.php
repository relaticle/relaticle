<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Google\Client as GoogleClient;
use Google\Service\Gmail;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class GmailService
{
    private Gmail $gmail;

    public function __construct(ConnectedAccount $account)
    {
        $client = new GoogleClient;
        $client->setClientId(config('services.gmail.client_id'));
        $client->setClientSecret(config('services.gmail.client_secret'));
        $client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $account->token_expires_at?->diffInSeconds(now()),
        ]);

        // Auto-refresh expired tokens and persist back to DB
        if ($client->isAccessTokenExpired()) {
            $newToken = $client->fetchAccessTokenWithRefreshToken();

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
     * TODO::name this method better, as it doesn't actually return the messages, just the new historyId. Maybe `syncSinceHistoryId` or something like that.
     */
    public function fetchDelta(string $historyId): array
    {
        $history = $this->gmail->users_history->listUsersHistory('me', [
            'startHistoryId' => $historyId,
            'historyTypes' => ['messageAdded', 'messageDeleted', 'messageModified'],
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
            'provider_message_id' => $message->getId(),          // Gmail internal ID
            'rfc_message_id' => $headers->get('message-id')?->getValue(), // RFC 2822 header
            'thread_id' => $message->getThreadId(),
            'in_reply_to' => $headers->get('in-reply-to')?->getValue(),
            'subject' => $headers->get('subject')?->getValue(),
            'snippet' => mb_substr(strip_tags((string) $message->getSnippet()), 0, 255),
            'sent_at' => now()->setTimestamp($message->getInternalDate() / 1000),
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

        // Collect all pages
        $messageIds = collect($response->getMessages() ?? [])
            ->map(fn ($m) => $m->getId());

        // Get current historyId to use as cursor going forward
        $profile = $this->gmail->users->getProfile('me');
        $historyId = $profile->getHistoryId();

        return [
            'message_ids' => $messageIds,
            'history_id' => $historyId,
        ];
    }

    private function resolveDirection($headers): string
    {
        // If the from address matches the account's email, it's outbound
        return 'inbound'; // simplified — implement based on account email address
    }

    private function extractBody($payload, string $mimeType): ?string
    {
        // Walk MIME parts recursively, base64-decode matching part
        return null; // simplified
    }

    private function extractParticipants($headers): array
    {
        $participants = [];

        foreach (['from', 'to', 'cc', 'bcc'] as $role) {
            $value = $headers->get($role)?->getValue();
            if ($value) {
                // Parse RFC 5322 address list
                // e.g. "John Doe <john@example.com>, jane@example.com"
                $participants[] = ['role' => $role, 'raw' => $value];
            }
        }

        return $participants;
    }
}
