<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Exceptions\AgentOutboxFull;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Queries\VisibleEmailsQuery;
use Relaticle\EmailIntegration\Support\AgentEmailBody;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;
use Throwable;

final readonly class QueueAgentEmailAction
{
    private const int MIN_HOLD_SECONDS = 60;

    public function __construct(
        private SendEmailAction $sendEmail,
        private AgentEmailBody $body,
        private VisibleEmailsQuery $emails,
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

        abort_unless(
            $workspace instanceof Workspace
                && $user->hasWorkspaceCapability($workspace->getKey(), WorkspaceCapability::EmailAgentSend),
            403,
        );

        $account = ConnectedAccount::query()
            ->ownedBy($user, $workspace)
            ->whereKey($data['connected_account_id'])
            ->first();

        if (! $account instanceof ConnectedAccount || ! $account->isSendable()) {
            throw ValidationException::withMessages([
                'connected_account_id' => 'Pick one of your own mailboxes that can send. List your mailboxes to find one.',
            ]);
        }

        $replyToId = $data['in_reply_to_email_id'] ?? null;
        $replyTo = $replyToId === null ? null : $this->emails->find($user, $replyToId);

        if ($replyToId !== null && ! $replyTo instanceof Email) {
            throw ValidationException::withMessages([
                'in_reply_to_email_id' => "Email with ID [{$replyToId}] not found.",
            ]);
        }

        $this->assertUnderAgentLimit($user);

        $holdSeconds = $this->holdSeconds();

        $email = $this->sendEmail->execute($user, [
            'connected_account_id' => (string) $account->getKey(),
            'subject' => $data['subject'],
            'body_html' => $this->body->forSending($data['body'], $account, $data['include_signature'] ?? true),
            'to' => $this->recipients($data['to']),
            'cc' => $this->recipients($data['cc'] ?? []),
            'bcc' => $this->recipients($data['bcc'] ?? []),
            'in_reply_to_email_id' => $replyTo?->getKey(),
            'creation_source' => $source,
            'batch_id' => null,
            'priority' => EmailPriority::PRIORITY,
            'scheduled_for' => now()->addSeconds($holdSeconds),
        ]);

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

    /**
     * @param  list<string>  $addresses
     * @return list<array{email: string, name: null}>
     */
    private function recipients(array $addresses): array
    {
        return array_map(fn (string $address): array => ['email' => $address, 'name' => null], $addresses);
    }
}
