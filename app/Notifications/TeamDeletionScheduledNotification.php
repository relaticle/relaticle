<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\EditTeam;
use App\Models\Team;
use App\Models\User;
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
        $timezone = $notifiable instanceof User ? $notifiable->effectiveTimezone() : (string) config('app.timezone');
        $date = $this->team->scheduled_deletion_at?->copy()->setTimezone($timezone)->format('M j, Y') ?? '';

        return (new MailMessage)
            ->subject(__('mail.team_deletion_scheduled.subject', ['team' => $this->team->name]))
            ->markdown('mail.notifications.team-deletion-scheduled', [
                'teamName' => $this->team->name,
                'date' => $date,
                'settingsUrl' => EditTeam::getUrl(panel: 'app', tenant: $this->team),
            ]);
    }
}
