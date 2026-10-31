<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\ForwardAttachmentCopyService;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;
use Throwable;

final readonly class SendAssistantEmailDraft
{
    public function __construct(
        private PrepareAgentDraftSend $prepareSend,
        private SendEmailAction $sendEmail,
        private DeleteEmailDraftAction $deleteDraft,
        private ForwardAttachmentCopyService $attachments,
        private QueuedSendNotifier $notifier,
    ) {}

    /** @param  array{draft_id: string, scheduled_for?: ?string}  $data */
    public function execute(User $user, array $data): Email
    {
        $email = DB::transaction(function () use ($user, $data): Email {
            $payload = $this->prepareSend->execute($user, $data['draft_id'], EmailCreationSource::CHAT, $data['scheduled_for'] ?? null);

            try {
                $email = $this->sendEmail->execute($user, [
                    ...$payload,
                    'scheduled_for' => $payload['scheduled_for']?->isFuture() === true ? $payload['scheduled_for'] : null,
                ]);
            } catch (Throwable $exception) {
                $this->attachments->deleteCopiedFiles($payload['attachments']);

                throw $exception;
            }

            $this->deleteDraft->executeIfExists($user, $data['draft_id']);

            return $email;
        });

        $this->notifier->send($email);

        return $email;
    }
}
