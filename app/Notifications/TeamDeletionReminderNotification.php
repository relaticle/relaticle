<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

final class TeamDeletionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Team $team,
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
            ->subject("{$this->team->name} will be deleted in {$days} ".Str::plural('day', $days))
            ->line("{$this->team->name} is scheduled for permanent deletion on {$this->team->scheduled_deletion_at->format('F j, Y')}.")
            ->line('This is your final reminder. All data will be permanently removed after this date.')
            ->line('You can cancel the deletion from your team settings at any time before that date.')
            ->salutation('Thank you for using Relaticle.');
    }
}
