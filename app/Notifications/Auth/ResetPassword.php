<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

final class ResetPassword extends FilamentResetPassword
{
    /**
     * @param  mixed  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.reset_password.subject'))
            ->markdown('mail.notifications.reset-password', [
                'url' => $this->resetUrl($notifiable),
                'count' => (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ]);
    }
}
