<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use Filament\Auth\Notifications\NoticeOfEmailChangeRequest as FilamentNoticeOfEmailChangeRequest;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3)]
#[Backoff(60, 300)]
final class NoticeOfEmailChangeRequest extends FilamentNoticeOfEmailChangeRequest implements ShouldBeEncrypted
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
