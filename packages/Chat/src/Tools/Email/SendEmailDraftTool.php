<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\EmailIntegration\Actions\CheckAgentDraftSend;
use Relaticle\EmailIntegration\Actions\SendAssistantEmailDraft;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final class SendEmailDraftTool extends BaseComposedEmailTool
{
    public function description(): string
    {
        return 'Propose sending one of the user\'s saved drafts exactly as it stands, including changes the user made in Relaticle. Returns a proposal the user approves; nothing is sent until they do.'
            .' The email reaches people outside the workspace, so propose it only when the user asked for it.'
            .' Once sent, the draft leaves Drafts. Pass `scheduled_for` to send it later instead of now.';
    }

    protected function actionClass(): string
    {
        return SendAssistantEmailDraft::class;
    }

    protected function entityType(): string
    {
        return ProposalEntity::EmailDraftSend->value;
    }

    protected function idKey(): string
    {
        return 'draft_id';
    }

    protected function composedEmail(User $user, string $id): ?Email
    {
        return resolve(ComposedEmailsQuery::class)->draft($user, $id);
    }

    protected function notFoundError(): string
    {
        return 'No draft of yours has that ID. List emails with status draft to find it.';
    }

    protected function takesSendTime(): bool
    {
        return true;
    }

    protected function refusal(User $user, Email $email, array $record): ?string
    {
        try {
            resolve(CheckAgentDraftSend::class)->execute($user, $email);
        } catch (ValidationException $exception) {
            return Arr::first(Arr::flatten($exception->errors()));
        }

        return null;
    }
}
