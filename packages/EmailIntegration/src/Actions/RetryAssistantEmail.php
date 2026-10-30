<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\ComposedEmailsQuery;

final readonly class RetryAssistantEmail
{
    public function __construct(
        private ComposedEmailsQuery $emails,
        private RetryFailedEmailAction $retry,
    ) {}

    /** @param  array{email_id: string}  $data */
    public function execute(User $user, array $data): Email
    {
        abort_unless($user->hasWorkspaceCapability($user->current_workspace_id, WorkspaceCapability::EmailAgentSend), 403);

        $email = $this->emails->outbox($user, $data['email_id'])
            ?? throw ValidationException::withMessages(['email_id' => 'This email is no longer in the outbox.']);

        return $this->retry->execute($email);
    }
}
