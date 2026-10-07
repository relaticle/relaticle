<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;

final readonly class SendAssistantEmail
{
    public function __construct(
        private PrepareAgentEmail $prepareEmail,
        private SendEmailAction $sendEmail,
        private QueuedSendNotifier $notifier,
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
     * }  $data
     */
    public function execute(User $user, array $data): Email
    {
        $email = $this->sendEmail->execute($user, $this->prepareEmail->execute($user, $data, EmailCreationSource::CHAT));

        $this->notifier->send($email);

        return $email;
    }
}
