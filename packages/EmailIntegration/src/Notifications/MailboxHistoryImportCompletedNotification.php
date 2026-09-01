<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
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
        $imported = $this->account->initial_sync_imported;
        $message = (new MailMessage)
            ->subject(__('filament/notifications/mailbox-import-complete.mail.subject'))
            ->greeting(__('filament/notifications/mailbox-import-complete.mail.greeting', [
                'name' => $notifiable instanceof User ? $notifiable->name : '',
            ]))
            ->line(__('filament/notifications/mailbox-import-complete.mail.line', [
                'email' => $this->account->email_address,
                'count' => $imported,
            ]));

        $team = Team::query()->find($this->account->team_id);

        if ($team instanceof Team) {
            $message->action(
                __('filament/notifications/mailbox-import-complete.mail.action'),
                EmailAccountsPage::getUrl(tenant: $team),
            );
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title(__('filament/notifications/mailbox-import-complete.title'))
            ->body(__('filament/notifications/mailbox-import-complete.body', [
                'email' => $this->account->email_address,
                'count' => $this->account->initial_sync_imported,
            ]))
            ->success()
            ->icon('heroicon-o-check-circle');

        $team = Team::query()->find($this->account->team_id);

        if ($team instanceof Team) {
            $notification->actions([
                Action::make('view')
                    ->label(__('filament/notifications/mailbox-import-complete.actions.view'))
                    ->url(EmailAccountsPage::getUrl(tenant: $team))
                    ->button(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }
}
