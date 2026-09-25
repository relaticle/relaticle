<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UserDeletionScheduledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly User $user,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->user->scheduled_deletion_at?->copy()->setTimezone($this->user->effectiveTimezone())->format('M j, Y') ?? '';

        return (new MailMessage)
            ->subject(__('mail.account_deletion_scheduled.subject'))
            ->markdown('mail.notifications.account-deletion-scheduled', [
                'date' => $date,
                'keepUrl' => route('filament.app.scheduled-deletion'),
            ]);
    }
}
