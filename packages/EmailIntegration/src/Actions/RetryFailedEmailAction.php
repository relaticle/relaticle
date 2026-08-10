<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use RuntimeException;

final readonly class RetryFailedEmailAction
{
    public function execute(Email $email): Email
    {
        return DB::transaction(function () use ($email): Email {
            /** @var Email $lockedEmail */
            $lockedEmail = Email::query()->lockForUpdate()->findOrFail($email->getKey());

            if ($lockedEmail->status !== EmailStatus::FAILED) {
                throw new RuntimeException("Only failed emails can be retried — status is {$lockedEmail->status->value}.");
            }

            // Keep `attempts` intact (a failed email has attempted >= 1). Zeroing it
            // would make EmailSendingService treat the next send as a first attempt and
            // skip the provider-side reconciliation lookup — re-delivering an email that
            // a prior attempt already handed to the provider before crashing.
            $lockedEmail->update([
                'status' => EmailStatus::QUEUED,
                'last_error' => null,
                'scheduled_for' => now(),
            ]);

            return $lockedEmail->refresh();
        });
    }
}
