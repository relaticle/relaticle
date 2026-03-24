<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\GmailService;

final class StoreEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 5;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly string $messageId,
        public readonly string $provider,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Skip if already stored (dedup by account + rfc_message_id)
        $exists = Email::where('connected_account_id', $this->connectedAccount->getKey())
            ->where('rfc_message_id', $this->messageId)
            ->exists();

        if ($exists) {
            return;
        }

        $raw = match ($this->provider) {
            'gmail' => (new GmailService($this->connectedAccount))->fetchMessage($this->messageId),
            // TODO: add other providers here
        };

        DB::transaction(function () use ($raw): void {
            $email = Email::create([
                'team_id' => $this->connectedAccount->team_id,
                'user_id' => $this->connectedAccount->user_id,
                'connected_account_id' => $this->connectedAccount->getKey(),
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

            // Store body in separate table
            $email->body()->create([
                'body_text' => $raw['body_text'],
                'body_html' => $raw['body_html'],
            ]);

            foreach ($raw['participants'] as $p) {
                EmailParticipant::create([
                    'email_id' => $email->getKey(),
                    'email_address' => $p['email_address'],
                    'name' => $p['name'] ?? null,
                    'role' => $p['role'],
                ]);
            }

            // EmailObserver::created() fires here and calls EmailLinkingService
        });
    }
}
