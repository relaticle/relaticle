<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Filament\Auth\Notifications\VerifyEmailChange as FilamentVerifyEmailChange;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;

final class VerifyEmailChange extends FilamentVerifyEmailChange
{
    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.verify_email_change.subject'))
            ->markdown('mail.notifications.verify-email-change', [
                'url' => $this->verificationUrl($notifiable),
                'email' => $notifiable instanceof AnonymousNotifiable ? (string) $notifiable->routeNotificationFor('mail') : (string) $notifiable->email,
                'count' => (int) config('auth.verification.expire', 60),
            ]);
    }
}
