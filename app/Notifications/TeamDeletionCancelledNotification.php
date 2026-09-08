<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Team;
use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TeamDeletionCancelledNotification extends Notification implements ShouldQueue
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
            ->subject(__('mail.team_deletion_cancelled.subject', ['team' => $this->team->name]))
            ->markdown('mail.notifications.team-deletion-cancelled', [
                'teamName' => $this->team->name,
                'teamUrl' => Filament::getPanel('app')->getUrl($this->team),
            ]);
    }
}
