<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Enums\EmailChallengePurpose;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent synchronously from SendEmailChallenge, never queued on its own: that
 * would put the plaintext code back on the queue unencrypted.
 */
final class EmailCode extends Notification
{
    public function __construct(
        private readonly string $code,
        private readonly EmailChallengePurpose $purpose,
        private readonly int $expiresInMinutes,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = "mail.email_code.purposes.{$this->purpose->value}";

        return (new MailMessage)
            ->subject(__("{$key}.subject"))
            ->markdown('mail.notifications.email-code', [
                'purpose' => $this->purpose,
                'code' => $this->code,
                'expiresInMinutes' => $this->expiresInMinutes,
            ]);
    }
}
