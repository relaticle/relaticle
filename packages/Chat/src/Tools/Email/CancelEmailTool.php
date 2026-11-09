<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Models\User;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\EmailIntegration\Actions\CancelAssistantEmail;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final class CancelEmailTool extends BaseComposedEmailTool
{
    public function description(): string
    {
        return 'Propose cancelling one of the user\'s queued emails before it goes out. Returns a proposal the user approves; the email stays queued until they do.';
    }

    protected function actionClass(): string
    {
        return CancelAssistantEmail::class;
    }

    protected function entityType(): string
    {
        return ProposalEntity::EmailCancel->value;
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
        return $email->status === EmailStatus::QUEUED ? null : "This email is {$email->status->value}, so it can no longer be cancelled.";
    }
}
