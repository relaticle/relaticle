<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use DateTimeInterface;
use Relaticle\EmailIntegration\Data\AgentConnection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;

final readonly class RescheduleAgentEmail
{
    public function __construct(
        private RescheduleQueuedEmailAction $reschedule,
        private PickAgentSendTime $pickSendTime,
        private QueuedSendNotifier $notifier,
    ) {}

    public function execute(User $user, Email $email, DateTimeInterface $requested, AgentConnection $connection): Email
    {
        $scheduledFor = $this->pickSendTime->execute($requested);

        $email = $this->reschedule->execute($email, $scheduledFor);

        $this->notifier->sendHeld($email, $user, $connection->name, (int) round(now()->diffInSeconds($scheduledFor)));

        return $email;
    }
}
