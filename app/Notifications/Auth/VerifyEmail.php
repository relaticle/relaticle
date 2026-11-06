<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Filament\Auth\Notifications\VerifyEmail as FilamentVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff(60, 300)]
final class VerifyEmail extends FilamentVerifyEmail
{
    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.verify_email.subject'))
            ->markdown('mail.notifications.verify-email', [
                'url' => $this->verificationUrl($notifiable),
                'count' => (int) config('auth.verification.expire', 60),
            ]);
    }
}
