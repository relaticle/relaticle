<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use DateTimeInterface;
use Relaticle\EmailIntegration\Models\Email;

final readonly class RescheduleAgentEmail
{
    public function __construct(
        private RescheduleQueuedEmailAction $reschedule,
        private PickAgentSendTime $pickSendTime,
    ) {}

    public function execute(Email $email, DateTimeInterface $requested): Email
    {
        return $this->reschedule->execute($email, $this->pickSendTime->execute($requested));
    }
}
