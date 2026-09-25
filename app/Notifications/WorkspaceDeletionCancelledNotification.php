<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WorkspaceDeletionCancelledNotification extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject(__('mail.workspace_deletion_cancelled.subject', ['workspace' => $this->workspace->name]))
            ->markdown('mail.notifications.workspace-deletion-cancelled', [
                'workspaceName' => $this->workspace->name,
                'workspaceUrl' => Filament::getPanel('app')->getUrl($this->workspace),
            ]);
    }
}
