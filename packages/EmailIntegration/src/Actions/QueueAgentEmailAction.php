<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\Email;

final readonly class QueueAgentEmailAction
{
    public function __construct(
        private PrepareAgentEmail $prepareEmail,
        private HoldAgentEmail $holdEmail,
    ) {}

    /**
     * @param  array{
     *     connected_account_id: string,
     *     to: list<string>,
     *     cc?: list<string>,
     *     bcc?: list<string>,
     *     subject: string,
     *     body: string,
     *     include_signature?: bool,
     *     in_reply_to_email_id?: ?string,
     *     scheduled_for?: ?string,
     * }  $data
     */
    public function execute(User $user, array $data, EmailCreationSource $source, string $via): Email
    {
        abort_unless($user->currentWorkspace instanceof Workspace, 403);

        return $this->holdEmail->execute($user, $this->prepareEmail->execute($user, $data, $source), $via);
    }
}
