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
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Str;

#[Tries(3)]
#[Backoff(60, 300)]
final class WorkspaceDeletionScheduledNotification extends Notification implements ShouldQueue
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
        $timezone = $notifiable instanceof User ? $notifiable->effectiveTimezone() : (string) config('app.timezone');
        $date = $this->workspace->scheduled_deletion_at?->copy()->setTimezone($timezone)->format('M j, Y') ?? '';

        return (new MailMessage)
            ->subject(__('mail.workspace_deletion_scheduled.subject', ['workspace' => $this->workspace->name]))
            ->markdown('mail.notifications.workspace-deletion-scheduled', [
                'workspaceName' => Str::squish($this->workspace->name),
                'date' => $date,
                'settingsUrl' => EditWorkspace::getUrl(panel: 'app', tenant: $this->workspace),
            ]);
    }
}
