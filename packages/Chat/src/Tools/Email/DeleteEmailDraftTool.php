<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\EmailIntegration\Actions\DeleteAssistantEmailDraft;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final class DeleteEmailDraftTool extends BaseComposedEmailTool
{
    public function description(): string
    {
        return 'Propose deleting one of the user\'s saved drafts. Returns a proposal the user approves; the draft stays until they do. Nothing is sent.';
    }

    protected function actionClass(): string
    {
        return DeleteAssistantEmailDraft::class;
    }

    protected function entityType(): string
    {
        return ProposalEntity::EmailDraftDelete->value;
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

    protected function requiredCapability(): ?WorkspaceCapability
    {
        return null;
    }
}
