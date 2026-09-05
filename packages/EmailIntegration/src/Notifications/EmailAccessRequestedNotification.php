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
            ->icon('heroicon-o-key')
            ->viewData(['request_id' => (string) $this->request->getKey()]);

        if ($email !== null) {
            $notification->actions([
                Action::make('viewEmail')
                    ->label(__('filament/notifications/email-access-requested.actions.view'))
                    ->link()
                    ->color('info')
                    ->dispatchTo(
                        EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                        'open-email-from-access-request',
                    )
                    ->eventData(['emailId' => $email->getKey()]),
                Action::make('accept')
                    ->label(__('filament/notifications/email-access-requested.actions.accept'))
                    ->link()
                    ->color('success')
                    ->close()
                    ->dispatchTo(
                        EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                        'approve-email-access-request',
                    )
                    ->eventData(['requestId' => (string) $this->request->getKey()]),
                Action::make('decline')
                    ->label(__('filament/notifications/email-access-requested.actions.decline'))
                    ->link()
                    ->color('warning')
                    ->close()
                    ->dispatchTo(
                        EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                        'deny-email-access-request',
                    )
                    ->eventData(['requestId' => (string) $this->request->getKey()]),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    /**
     * Delete the owner's bell row for this request. Same outcome as the
     * notification close (X) action: the row is removed, not marked read.
     */
    public static function dismissFor(EmailAccessRequest $request): void
    {
        $owner = $request->owner ?? User::query()->whereKey($request->owner_id)->first();

        if (! $owner instanceof User) {
            return;
        }

        $owner->notifications()
            ->where('type', self::class)
            ->where('data->viewData->request_id', (string) $request->getKey())
            ->delete();
    }
}
