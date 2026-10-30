<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Models\User;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\EmailIntegration\Actions\RetryAssistantEmail;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final class RetryEmailTool extends BaseComposedEmailTool
{
    public function description(): string
    {
        return 'Propose sending one of the user\'s failed emails again. Returns a proposal the user approves; nothing is sent until they do.'
            .' The email reaches people outside the workspace. Read its last error first: a retry only helps when the cause has passed.';
    }

    protected function actionClass(): string
    {
        return RetryAssistantEmail::class;
    }

    protected function entityType(): string
    {
        return ProposalEntity::EmailRetry->value;
    }

    protected function idKey(): string
    {
        return 'email_id';
    }

    protected function composedEmail(User $user, string $id): ?Email
    {
        return resolve(ComposedEmailsQuery::class)->outbox($user, $id);
    }

    protected function notFoundError(): string
    {
        return 'No email of yours in the outbox has that ID. List emails with status queued or failed to find it.';
    }

    protected function refusal(User $user, Email $email, array $record): ?string
    {
        return $email->status === EmailStatus::FAILED ? null : "Only a failed email can be sent again. This one is {$email->status->value}.";
    }
}
