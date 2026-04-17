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

            $lockedEmail->update([
                'status' => EmailStatus::QUEUED,
                'last_error' => null,
                'attempts' => 0,
                'scheduled_for' => now(),
            ]);

            return $lockedEmail->refresh();
        });
    }
}
