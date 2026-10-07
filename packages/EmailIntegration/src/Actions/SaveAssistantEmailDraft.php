<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\Email;

final readonly class SaveAssistantEmailDraft
{
    public function __construct(private SaveAgentEmailDraft $saveDraft) {}

    /**
     * @param  array{
     *     connected_account_id: string,
     *     to?: list<string>,
     *     cc?: list<string>,
     *     bcc?: list<string>,
     *     subject?: ?string,
     *     body?: ?string,
     *     include_signature?: bool,
     *     in_reply_to_email_id?: ?string,
     * }  $data
     */
    public function execute(User $user, array $data): Email
    {
        return $this->saveDraft->execute($user, $data, EmailCreationSource::CHAT);
    }
}
