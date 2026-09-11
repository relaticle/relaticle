<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Models\User;
use Filament\Notifications\Notification;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\AllowedRecipientService;

trait AssertsAllowedEmailRecipients
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     */
    protected function assertAllowedEmailRecipients(
        User $user,
        array $to,
        array $cc,
        array $bcc,
        ?Email $threadSource = null,
    ): bool {
        $errors = resolve(AllowedRecipientService::class)->validationErrors(
            $user,
            $to,
            $cc,
            $bcc,
            $this->threadParticipantAddresses($threadSource),
        );

        if ($errors === []) {
            return true;
        }

        Notification::make()
            ->title($errors[array_key_first($errors)][0])
            ->danger()
            ->send();

        return false;
    }

    /**
     * @return list<string>
     */
    protected function threadParticipantAddresses(?Email $email): array
    {
        if (! $email instanceof Email) {
            return [];
        }

        return $email->participants
            ->pluck('email_address')
            ->filter(fn (?string $address): bool => filled($address))
            ->map(fn (string $address): string => $address)
            ->all();
    }
}
