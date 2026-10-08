<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use RuntimeException;
use Throwable;

final readonly class DisconnectFormerMemberMailbox
{
    public function __construct(
        private CancelQueuedEmailAction $cancelQueued,
        private DisconnectConnectedAccountAction $disconnect,
    ) {}

    public function execute(ConnectedAccount $mailbox): void
    {
        $mailbox->outgoingEmails()
            ->where('status', EmailStatus::QUEUED)
            ->lazyById()
            ->each(fn (Email $email) => rescue(
                fn (): Email => $this->cancelQueued->execute($email),
                // The dispatcher claimed it first; a sending email is past cancelling.
                report: fn (Throwable $exception): bool => ! $exception instanceof RuntimeException,
            ));

        $this->disconnect->execute($mailbox);
    }
}
