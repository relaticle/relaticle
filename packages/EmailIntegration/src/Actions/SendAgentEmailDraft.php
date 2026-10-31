<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\ForwardAttachmentCopyService;
use Throwable;

final readonly class SendAgentEmailDraft
{
    public function __construct(
        private PrepareAgentDraftSend $prepareSend,
        private HoldAgentEmail $holdEmail,
        private DeleteEmailDraftAction $deleteDraft,
        private ForwardAttachmentCopyService $attachments,
    ) {}

    /** @throws ValidationException */
    public function execute(User $user, string $draftId, ?string $scheduledFor, string $via): Email
    {
        return DB::transaction(function () use ($user, $draftId, $scheduledFor, $via): Email {
            $payload = $this->prepareSend->execute($user, $draftId, EmailCreationSource::MCP, $scheduledFor);

            try {
                $email = $this->holdEmail->execute($user, $payload, $via);
            } catch (Throwable $exception) {
                $this->attachments->deleteCopiedFiles($payload['attachments']);

                throw $exception;
            }

            $this->deleteDraft->executeIfExists($user, $draftId);

            return $email;
        });
    }
}
