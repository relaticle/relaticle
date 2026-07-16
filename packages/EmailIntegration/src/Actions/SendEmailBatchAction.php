<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Models\EmailTemplate;
use Relaticle\EmailIntegration\Services\EmailTemplateRenderService;

final readonly class SendEmailBatchAction
{
    public function __construct(
        private EmailTemplateRenderService $renderService,
        private SendEmailAction $sendEmail,
    ) {}

    /**
     * Create a batch and queue one outbound email per recipient.
     *
     * All-or-nothing: SendEmailAction throws once the per-user queue cap is hit.
     * Without a transaction a mid-loop failure would leave the batch with
     * total_recipients set to the full count but only some emails queued, so
     * sent_count+failed_count can never reach total and the batch is stuck
     * Queued forever. Wrap creation + every send so a failure rolls all of it
     * back, leaving no orphaned batch.
     *
     * @param  list<array{person: People, email: string}>  $recipients
     * @param  array{connected_account_id: string, subject: string, body_html: string}  $payload
     */
    public function execute(User $user, array $recipients, array $payload, ?EmailTemplate $template = null): EmailBatch
    {
        $accountId = $payload['connected_account_id'];

        // Authorize the sender owns the chosen account in the current team; the
        // per-recipient People records are this team's own selection from the
        // PeopleResource table, already tenant-scoped by Filament.
        ConnectedAccount::query()
            ->ownedBy($user, $user->currentTeam)
            ->whereKey($accountId)
            ->firstOrFail();

        return DB::transaction(function () use ($user, $recipients, $payload, $template, $accountId): EmailBatch {
            $batch = EmailBatch::query()->create([
                'team_id' => $user->currentTeam?->getKey(),
                'user_id' => $user->getKey(),
                'connected_account_id' => $accountId,
                'subject' => $payload['subject'],
                'total_recipients' => count($recipients),
                'status' => EmailBatchStatus::Queued,
            ]);

            foreach ($recipients as $recipient) {
                $person = $recipient['person'];

                $rendered = $template instanceof EmailTemplate
                    ? $this->renderService->render($template, $person)
                    : [
                        'subject' => $this->renderService->renderContent($payload['subject'], $person),
                        'body_html' => $this->renderService->renderContent($payload['body_html'], $person),
                    ];

                $this->sendEmail->execute(
                    data: [
                        'connected_account_id' => $accountId,
                        'subject' => $rendered['subject'],
                        'body_html' => $rendered['body_html'],
                        'to' => [['email' => $recipient['email'], 'name' => $person->name]],
                        'cc' => [],
                        'bcc' => [],
                        'in_reply_to_email_id' => null,
                        'creation_source' => EmailCreationSource::MASS_SEND,
                        'privacy_tier' => EmailPrivacyTier::FULL,
                        'batch_id' => $batch->getKey(),
                    ],
                    linkToType: People::class,
                    linkToId: $person->getKey(),
                );
            }

            return $batch;
        });
    }
}
