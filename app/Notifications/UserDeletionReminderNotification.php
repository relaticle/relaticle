<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

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

        return (new MailMessage)
            ->subject("Final reminder: your account will be deleted in {$days} ".Str::plural('day', $days))
            ->line("Your account is scheduled for permanent deletion on {$this->user->scheduled_deletion_at->format('F j, Y')}.")
            ->line('This is your final reminder. All data will be permanently removed after this date.')
            ->line('To cancel, log in to your account before the deletion date.')
            ->salutation('Thank you for using Relaticle.');
    }
}
