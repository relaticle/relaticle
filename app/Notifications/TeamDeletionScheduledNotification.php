<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TeamDeletionScheduledNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject("{$this->team->name} is scheduled for deletion")
            ->line("{$this->team->name} has been scheduled for deletion on {$this->team->scheduled_deletion_at->format('F j, Y')}.")
            ->line('All team data, including contacts, companies, tasks, opportunities, and notes, will be permanently removed after this date.')
            ->line('You can cancel the deletion from your team settings at any time before that date.')
            ->salutation('Thank you for using Relaticle.');
    }
}
