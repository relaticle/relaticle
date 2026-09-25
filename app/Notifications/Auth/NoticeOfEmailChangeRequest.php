<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Filament\Auth\Notifications\NoticeOfEmailChangeRequest as FilamentNoticeOfEmailChangeRequest;
use Illuminate\Notifications\Messages\MailMessage;

final class NoticeOfEmailChangeRequest extends FilamentNoticeOfEmailChangeRequest
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.email_change_notice.subject'))
            ->markdown('mail.notifications.email-change-notice', [
                'newEmail' => $this->newEmail,
                'blockUrl' => $this->blockVerificationUrl,
            ]);
    }
}
