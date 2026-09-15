<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class MailboxHistoryImportCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly ConnectedAccount $account) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $workspace = $this->account->workspace;

        return (new MailMessage)
            ->subject(__('mail.mailbox_import_complete.subject', [
                'team' => $workspace->name,
            ]))
            ->markdown('mail.notifications.mailbox-import-complete', [
                'greetingName' => $notifiable instanceof User ? $notifiable->name : '',
                'connectedEmail' => $this->account->email_address,
                'teamName' => $workspace->name,
                'emailCount' => $this->account->initial_sync_imported,
                'calendarCount' => $this->account->initial_calendar_sync_imported,
                'workspaceUrl' => Dashboard::getUrl(['tenant' => $workspace]),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__('filament/notifications/mailbox-import-complete.title'))
            ->body(__('filament/notifications/mailbox-import-complete.body', [
                'email' => $this->account->email_address,
                'team' => $this->account->workspace->name,
                'emails' => $this->account->initial_sync_imported,
                'events' => $this->account->initial_calendar_sync_imported,
            ]))
            ->success()
            ->icon('heroicon-o-check-circle')
            ->getDatabaseMessage();
    }
}
