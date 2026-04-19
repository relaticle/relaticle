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
        return (new MailMessage)
            ->subject('Your account is scheduled for deletion')
            ->greeting("Hello {$this->user->name},")
            ->line("Your account is scheduled for permanent deletion on {$this->user->scheduled_deletion_at->format('F j, Y')}.")
            ->line('All your data will be permanently removed after this date.')
            ->line('If you changed your mind, simply log in anytime before that date to cancel the deletion.')
            ->salutation('Thank you for using Relaticle.');
    }
}
