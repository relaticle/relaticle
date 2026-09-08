<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UserDeletionReminderNotification extends Notification implements ShouldQueue
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
        $days = (int) config('relaticle.deletion.reminder_days_before');
        $date = $this->user->scheduled_deletion_at?->copy()->setTimezone($this->user->effectiveTimezone())->format('M j, Y') ?? '';

        return (new MailMessage)
            ->subject(trans_choice('mail.account_deletion_reminder.subject', $days, ['days' => $days]))
            ->markdown('mail.notifications.account-deletion-reminder', [
                'days' => $days,
                'date' => $date,
                'keepUrl' => route('filament.app.scheduled-deletion'),
            ]);
    }
}
