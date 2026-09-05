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
            ->body($subject)
            ->warning()
            ->icon('heroicon-o-key');

        if ($email !== null) {
            $notification->actions([
                Action::make('viewEmail')
                    ->label(__('filament/notifications/email-access-requested.actions.view'))
                    ->link()
                    ->color('info')
                    ->dispatchTo(
                        EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                        'open-email-from-access-request',
                        ['emailId' => $email->getKey()],
                    ),
                Action::make('accept')
                    ->label(__('filament/notifications/email-access-requested.actions.accept'))
                    ->link()
                    ->color('success')
                    ->markAsRead()
                    ->dispatchTo(
                        EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                        'approve-email-access-request',
                        ['requestId' => $this->request->getKey()],
                    ),
                Action::make('decline')
                    ->label(__('filament/notifications/email-access-requested.actions.decline'))
                    ->link()
                    ->color('warning')
                    ->markAsRead()
                    ->dispatchTo(
                        EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                        'deny-email-access-request',
                        ['requestId' => $this->request->getKey()],
                    ),
            ]);
        }

        return $notification->getDatabaseMessage();
    }
}
