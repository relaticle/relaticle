<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Data\AgentConnection;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;

final readonly class RequeueAgentEmail
{
    public function __construct(
        private ReserveAgentHold $reserveHold,
        private RetryFailedEmailAction $retry,
        private RescheduleQueuedEmailAction $reschedule,
        private PickAgentSendTime $pickSendTime,
        private QueuedSendNotifier $notifier,
    ) {}

    public function execute(User $user, Email $email, AgentConnection $connection): Email
    {
        $scheduledFor = $this->pickSendTime->execute();

        $email = DB::transaction(function () use ($user, $email, $scheduledFor, $connection): Email {
            $isAgentEmail = $email->creation_source === EmailCreationSource::MCP;

            if ($isAgentEmail) {
                $this->reserveHold->execute($user);
            }

            $retried = $this->retry->execute($email);

            if ($isAgentEmail) {
                $retried->forceFill($connection->credential())->save();
            }

            return $this->reschedule->execute($retried, $scheduledFor);
        });

        $this->notifier->sendHeld($email, $user, $connection->name, (int) round(now()->diffInSeconds($scheduledFor)));

        return $email;
    }
}
