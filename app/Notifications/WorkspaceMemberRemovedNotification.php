<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WorkspaceMemberRemovedNotification extends Notification implements ShouldQueue
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
            ->subject(__('mail.workspace_member_removed.subject', ['workspace' => $this->workspace->name]))
            ->markdown('mail.notifications.workspace-member-removed', [
                'workspaceName' => $this->workspace->name,
                'appUrl' => url()->getAppUrl(),
            ]);
    }
}
