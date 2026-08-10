<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use RuntimeException;

final readonly class RescheduleQueuedEmailAction
{
    public function execute(Email $email, DateTimeInterface $newScheduledFor): Email
    {
        return DB::transaction(function () use ($email, $newScheduledFor): Email {
            /** @var Email $lockedEmail */
            $lockedEmail = Email::query()->lockForUpdate()->findOrFail($email->getKey());

            if ($lockedEmail->status !== EmailStatus::QUEUED) {
                throw new RuntimeException("Email cannot be rescheduled — status is {$lockedEmail->status->value}.");
            }

            $lockedEmail->update(['scheduled_for' => Date::instance($newScheduledFor)]);

            return $lockedEmail->refresh();
        });
    }
}
