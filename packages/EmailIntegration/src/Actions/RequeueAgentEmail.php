<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
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

    public function execute(User $user, Email $email, string $via): Email
    {
        $scheduledFor = $this->pickSendTime->execute();

        $email = DB::transaction(function () use ($user, $email, $scheduledFor): Email {
            if ($email->creation_source === EmailCreationSource::MCP) {
                $this->reserveHold->execute($user);
            }

            return $this->reschedule->execute($this->retry->execute($email), $scheduledFor);
        });

        $this->notifier->sendHeld($email, $user, $via, (int) round(now()->diffInSeconds($scheduledFor)));

        return $email;
    }
}
