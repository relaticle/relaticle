<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Exceptions\AgentOutboxFull;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;
use Throwable;

final readonly class QueueAgentEmailAction
{
    private const int MIN_HOLD_SECONDS = 60;

    public function __construct(
        private SendEmailAction $sendEmail,
        private PrepareAgentEmail $prepareEmail,
        private QueuedSendNotifier $notifier,
        private CancelQueuedEmailAction $cancelEmail,
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
    public function execute(User $user, array $data, EmailCreationSource $source, string $via): Email
    {
        $workspace = $user->currentWorkspace;

        abort_unless($workspace instanceof Workspace, 403);

        $payload = $this->prepareEmail->execute($user, $data, $source);

        $this->assertUnderAgentLimit($user);

        $holdSeconds = $this->holdSeconds();

        $email = $this->sendEmail->execute($user, [...$payload, 'scheduled_for' => now()->addSeconds($holdSeconds)]);

        try {
            $this->notifier->sendHeld($email, $user, $workspace, $via, $holdSeconds);
        } catch (Throwable $exception) {
            rescue(fn (): Email => $this->cancelEmail->execute($email));

            throw $exception;
        }

        return $email;
    }

    private function holdSeconds(): int
    {
        return max(self::MIN_HOLD_SECONDS, Config::integer('email-integration.outbox.agent_send_hold_seconds'));
    }

    private function assertUnderAgentLimit(User $user): void
    {
        $limit = Config::integer('email-integration.outbox.agent_max_held_per_user');

        $held = Email::query()
            ->where('user_id', $user->getKey())
            ->where('status', EmailStatus::QUEUED)
            ->createdOverMcp()
            ->count();

        throw_if($held >= $limit, AgentOutboxFull::atLimit($limit));
    }
}
