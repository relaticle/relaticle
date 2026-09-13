<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Filament\Pages\EditWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WorkspaceDeletionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Workspace $workspace,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $days = (int) config('relaticle.deletion.reminder_days_before');
        $timezone = $notifiable instanceof User ? $notifiable->effectiveTimezone() : (string) config('app.timezone');
        $date = $this->workspace->scheduled_deletion_at?->copy()->setTimezone($timezone)->format('M j, Y') ?? '';

        return (new MailMessage)
            ->subject(trans_choice('mail.workspace_deletion_reminder.subject', $days, ['workspace' => $this->workspace->name, 'days' => $days]))
            ->markdown('mail.notifications.workspace-deletion-reminder', [
                'workspaceName' => $this->workspace->name,
                'days' => $days,
                'date' => $date,
                'settingsUrl' => EditWorkspace::getUrl(panel: 'app', tenant: $this->workspace),
            ]);
    }
}
