<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Data\AgentConnection;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;

final readonly class RescheduleAgentEmail
{
    public function __construct(
        private RescheduleQueuedEmailAction $reschedule,
        private ReserveAgentHold $reserveHold,
        private PickAgentSendTime $pickSendTime,
        private QueuedSendNotifier $notifier,
    ) {}

    public function execute(User $user, Email $email, DateTimeInterface $requested, AgentConnection $connection): Email
    {
        $scheduledFor = $this->pickSendTime->execute($requested);

        $email = DB::transaction(function () use ($user, $email, $scheduledFor): Email {
            if ($email->creation_source === EmailCreationSource::MCP) {
                $this->reserveHold->execute($user, $scheduledFor, $email);
            }

            return $this->reschedule->execute($email, $scheduledFor);
        });

        $this->notifier->sendRescheduled($email, $user, $connection->name, (int) round(now()->diffInSeconds($scheduledFor)));

        return $email;
    }
}
