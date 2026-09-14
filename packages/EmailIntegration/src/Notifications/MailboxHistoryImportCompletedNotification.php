<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

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
        return (new MailMessage)
            ->subject(__('filament/notifications/mailbox-import-complete.mail.subject'))
            ->greeting(__('filament/notifications/mailbox-import-complete.mail.greeting', [
                'name' => $notifiable instanceof User ? $notifiable->name : '',
            ]))
            ->line(__('filament/notifications/mailbox-import-complete.mail.line', [
                'email' => $this->account->email_address,
                'count' => $this->account->initial_sync_imported,
            ]));
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
                'count' => $this->account->initial_sync_imported,
            ]))
            ->success()
            ->icon('heroicon-o-check-circle')
            ->getDatabaseMessage();
    }
}
