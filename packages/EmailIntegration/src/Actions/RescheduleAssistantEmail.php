<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final readonly class RescheduleAssistantEmail
{
    public function __construct(
        private ComposedEmailsQuery $emails,
        private RescheduleQueuedEmailAction $reschedule,
    ) {}

    /** @param  array{email_id: string, scheduled_for: string}  $data */
    public function execute(User $user, array $data): Email
    {
        abort_unless($user->hasWorkspaceCapability($user->current_workspace_id, WorkspaceCapability::EmailAgentSend), 403);

        $email = $this->emails->outbox($user, $data['email_id'])
            ?? throw ValidationException::withMessages(['email_id' => 'This email is no longer in the outbox.']);

        $sendAt = Date::parse($data['scheduled_for'])->utc();

        if (! $sendAt->isFuture()) {
            throw ValidationException::withMessages(['scheduled_for' => 'That time has passed. Pick a later one.']);
        }

        return $this->reschedule->execute($email, $sendAt);
    }
}
