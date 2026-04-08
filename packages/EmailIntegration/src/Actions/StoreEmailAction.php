<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\GmailService;
use Throwable;

final readonly class StoreEmailAction
{
    public function __construct(
        private LinkEmailAction $linkEmail,
    ) {}

    /**
     * Fetch and store an email from the provider, then link it to CRM records.
     * Returns null when the message is already stored (dedup by account + rfc_message_id).
     *
     * @throws Throwable
     */
    public function execute(ConnectedAccount $connectedAccount, string $messageId, EmailProvider $provider): ?Email
    {
        if ($this->isAlreadyStored($connectedAccount, $messageId)) {
            return null;
        }

        $raw = match ($provider) {
            EmailProvider::GMAIL => new GmailService($connectedAccount)->fetchMessage($messageId),
            EmailProvider::AZURE => throw new Exception('To be implemented'),
        };

        return DB::transaction(function () use ($connectedAccount, $raw): Email {
            $email = Email::query()->create([
                'team_id' => $connectedAccount->team_id,
                'user_id' => $connectedAccount->user_id,
                'connected_account_id' => $connectedAccount->getKey(),
                'rfc_message_id' => $raw['rfc_message_id'],
                'provider_message_id' => $raw['provider_message_id'],
                'thread_id' => $raw['thread_id'],
                'in_reply_to' => $raw['in_reply_to'] ?? null,
                'subject' => $raw['subject'],
                'snippet' => $raw['snippet'] ?? null,
                'sent_at' => $raw['sent_at'],
                'direction' => $raw['direction'],
                'folder' => $raw['folder'] ?? null,
                'has_attachments' => $raw['has_attachments'] ?? false,
            ]);

            $email->body()->create([
                'body_text' => $raw['body_text'],
                'body_html' => $raw['body_html'],
            ]);

            foreach ($raw['participants'] as $participant) {
                EmailParticipant::query()->create([
                    'email_id' => $email->getKey(),
                    'email_address' => $participant['email_address'],
                    'name' => $participant['name'] ?? null,
                    'role' => $participant['role'],
                ]);
            }

            // Detect internal emails — true when all participants are workspace members.
            $teamUserEmails = User::query()->where('current_team_id', $email->team_id)
                ->pluck('email')
                ->map(fn ($e) => strtolower($e));

            $participantAddresses = $email->participants()->pluck('email_address')
                ->map(fn ($e) => strtolower($e));

            $isInternal = $participantAddresses->isNotEmpty() && $participantAddresses->every(
                fn ($address) => $teamUserEmails->contains($address)
            );

            $email->updateQuietly(['is_internal' => $isInternal]);

            $this->linkEmail->execute($email);

            return $email;
        });
    }

    private function isAlreadyStored(ConnectedAccount $connectedAccount, string $messageId): bool
    {
        return Email::query()->where('connected_account_id', $connectedAccount->getKey())
            ->where('rfc_message_id', $messageId)
            ->exists();
    }
}
