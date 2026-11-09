<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Email;

use App\Models\User;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\EmailIntegration\Actions\RescheduleAssistantEmail;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final class RescheduleEmailTool extends BaseComposedEmailTool
{
    public function description(): string
    {
        return 'Propose changing when one of the user\'s queued emails is sent. Returns a proposal the user approves; the time stays as it is until they do.';
    }

    protected function actionClass(): string
    {
        return RescheduleAssistantEmail::class;
    }

    protected function entityType(): string
    {
        return ProposalEntity::EmailReschedule->value;
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

    protected function takesSendTime(): bool
    {
        return true;
    }

    protected function refusal(User $user, Email $email, array $record): ?string
    {
        return match (true) {
            blank($record['scheduled_for'] ?? null) => 'Pass scheduled_for: the new time to send at.',
            $email->status !== EmailStatus::QUEUED => "This email is {$email->status->value}, so it can no longer be rescheduled.",
            default => null,
        };
    }
}
