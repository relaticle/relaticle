<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final class EmailAccessRequestedNotification extends Notification
{
    use Queueable;

    private const string DATABASE_NOTIFICATIONS_MODAL_ID = 'database-notifications';

    public function __construct(public readonly EmailAccessRequest $request) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $email = $this->request->email;
        $requesterName = $this->request->requester->name;
        $subject = $email !== null ? ($email->subject ?? __('filament/notifications/email-access-requested.no_subject')) : __('filament/notifications/email-access-requested.no_subject');

        $notification = FilamentNotification::make()
            ->title(__('filament/notifications/email-access-requested.title', ['name' => $requesterName]))
            ->warning()
            ->icon('heroicon-o-key');

        if ($email !== null) {
            $notification->actions([
                Action::make('viewEmail')
                    ->label($subject)
                    ->link()
                    ->alpineClickHandler($this->viewEmailClickHandler($email->getKey())),
                Action::make('accept')
                    ->label(__('filament/notifications/email-access-requested.actions.accept'))
                    ->button()
                    ->color('success')
                    ->alpineClickHandler($this->approveClickHandler($this->request->getKey())),
                Action::make('decline')
                    ->label(__('filament/notifications/email-access-requested.actions.decline'))
                    ->color('gray')
                    ->alpineClickHandler($this->denyClickHandler($this->request->getKey())),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    private function viewEmailClickHandler(string $emailId): string
    {
        return sprintf(
            '$dispatch(\'close-modal\', { id: \'%s\' }); close(); setTimeout(() => window.Livewire.dispatchTo(\'%s\', \'open-email-from-access-request\', { emailId: \'%s\' }), 0)',
            self::DATABASE_NOTIFICATIONS_MODAL_ID,
            EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
            $emailId,
        );
    }

    private function approveClickHandler(string $requestId): string
    {
        return sprintf(
            'window.Livewire.dispatchTo(\'%s\', \'approve-email-access-request\', { requestId: \'%s\' }); close(); $dispatch(\'close-modal\', { id: \'%s\' })',
            EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
            $requestId,
            self::DATABASE_NOTIFICATIONS_MODAL_ID,
        );
    }

    private function denyClickHandler(string $requestId): string
    {
        return sprintf(
            'window.Livewire.dispatchTo(\'%s\', \'deny-email-access-request\', { requestId: \'%s\' }); close(); $dispatch(\'close-modal\', { id: \'%s\' })',
            EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
            $requestId,
            self::DATABASE_NOTIFICATIONS_MODAL_ID,
        );
    }
}
