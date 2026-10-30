<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Exceptions\AgentOutboxFull;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;

final readonly class HoldAgentEmail
{
    public function __construct(
        private SendEmailAction $sendEmail,
        private PickAgentSendTime $pickSendTime,
        private QueuedSendNotifier $notifier,
    ) {}

    /**
     * @param  array{
     *     connected_account_id: string,
     *     subject: string,
     *     body_html: string,
     *     to: list<array{email: string, name: null}>,
     *     cc: list<array{email: string, name: null}>,
     *     bcc: list<array{email: string, name: null}>,
     *     in_reply_to_email_id: ?string,
     *     creation_source: EmailCreationSource,
     *     batch_id: null,
     *     priority: EmailPriority,
     *     scheduled_for?: ?DateTimeInterface,
     *     attachments?: list<string>,
     *     attachment_file_names?: array<string, string>,
     *     attachment_attributes?: array<string, array{is_inline: bool, content_id: ?string}>,
     * }  $payload
     */
    public function execute(User $user, array $payload, string $via): Email
    {
        return DB::transaction(function () use ($user, $payload, $via): Email {
            $this->lockOutboxOf($user);

            $this->assertUnderAgentLimit($user);

            $scheduledFor = $this->pickSendTime->execute($payload['scheduled_for'] ?? null);

            $email = $this->sendEmail->execute($user, [...$payload, 'scheduled_for' => $scheduledFor]);

            $this->notifier->sendHeld($email, $user, $via, (int) round(now()->diffInSeconds($scheduledFor)));

            return $email;
        });
    }

    private function lockOutboxOf(User $user): void
    {
        User::query()->whereKey($user->getKey())->lockForUpdate()->first();
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
