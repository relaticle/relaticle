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
            if ($email->creation_source === EmailCreationSource::MCP) {
                $this->reserveHold->execute($user);
                $email->forceFill($connection->credential())->save();
            }

            return $this->reschedule->execute($this->retry->execute($email), $scheduledFor);
        });

        $this->notifier->sendHeld($email, $user, $connection->name, (int) round(now()->diffInSeconds($scheduledFor)));

        return $email;
    }
}
