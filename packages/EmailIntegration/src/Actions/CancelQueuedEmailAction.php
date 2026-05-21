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

            $isCancellable = match (true) {
                $lockedEmail->status === EmailStatus::QUEUED => true,
                $lockedEmail->status === EmailStatus::SENDING && $lockedEmail->provider_message_id === null => true,
                default => false,
            };

            if (! $isCancellable) {
                throw new RuntimeException("Email cannot be cancelled — status is {$lockedEmail->status->value}.");
            }

            $lockedEmail->update(['status' => EmailStatus::CANCELLED]);

            return $lockedEmail->refresh();
        });
    }
}
