<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Jobs\ClassifyEmailJob;
use App\Models\User;
use Exception;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\EmailLinkingService;
use Relaticle\EmailIntegration\Services\GmailService;
use Throwable;

final class StoreEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 5;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
        public readonly string $messageId,
        public readonly EmailProvider $provider,
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        // Skip if already stored (dedup by account + rfc_message_id)

        if ($this->isAlreadyStored()) {
            return;
        }

        $raw = match ($this->provider) {
            EmailProvider::GMAIL => new GmailService($this->connectedAccount)->fetchMessage($this->messageId),
            EmailProvider::AZURE => throw new Exception('To be implemented'),
            // TODO: add other providers here
        };

        DB::transaction(function () use ($raw): void {
            $email = Email::query()->create([
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
            $this->storeEmailBody($email, $raw['body_text'], $raw['body_html']);

            foreach ($raw['participants'] as $participant) {
                EmailParticipant::query()->create([
                    'email_id' => $email->getKey(),
                    'email_address' => $participant['email_address'],
                    'name' => $participant['name'] ?? null,
                    'role' => $participant['role'],
                ]);
            }

            // Detect internal emails — true when all participants are workspace members
            $teamUserEmails = User::query()->where('current_team_id', $email->team_id)
                ->pluck('email')
                ->map(fn ($e) => strtolower($e));

            $participants = $email->participants()->pluck('email_address')
                ->map(fn ($e) => strtolower($e));

            $isInternal = $participants->isNotEmpty() && $participants->every(
                fn ($address) => $teamUserEmails->contains($address)
            );

            $email->updateQuietly(['is_internal' => $isInternal]);

            // Trigger linking and classification now that participants and is_internal are set.
            app(EmailLinkingService::class)->linkEmail($email);
            //            ClassifyEmailJob::dispatch($email->getKey())->delay(now()->addSeconds(5));
        });
    }

    private function isAlreadyStored(): bool
    {
        return Email::query()->where('connected_account_id', $this->connectedAccount->getKey())
            ->where('rfc_message_id', $this->messageId)
            ->exists();
    }

    private function storeEmailBody(Email $email, ?string $bodyText, string $bodyHtml): void
    {
        $email->body()->create([
            'body_text' => $bodyText,
            'body_html' => $bodyHtml,
        ]);
    }
}
