<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use RuntimeException;

final readonly class CancelQueuedEmailAction
{
    public function execute(Email $email): Email
    {
        return DB::transaction(function () use ($email): Email {
            /** @var Email $lockedEmail */
            $lockedEmail = Email::query()->lockForUpdate()->findOrFail($email->getKey());

            // Only QUEUED mail is cancellable. The undo window keeps the email QUEUED
            // (scheduled_for ~30s out) until the dispatcher claims it, so undo always
            // races against the QUEUED state. Once claimed to SENDING a worker is
            // actively delivering it: send() calls the provider OUTSIDE any row lock,
            // so a "SENDING && provider_message_id === null" check is not a reliable
            // "not yet delivered" signal — cancelling there could mark CANCELLED an
            // email the provider already accepted (and updateSentEmail would then race
            // it back to SENT). Treat SENDING as too late.
            if ($lockedEmail->status !== EmailStatus::QUEUED) {
                throw new RuntimeException("Email cannot be cancelled — status is {$lockedEmail->status->value}.");
            }

            $lockedEmail->update(['status' => EmailStatus::CANCELLED]);

            return $lockedEmail->refresh();
        });
    }
}
