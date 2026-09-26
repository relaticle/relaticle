<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\Email;

final readonly class QueuedSendNotifier
{
    public function send(Email $email): void
    {
        $notification = Notification::make()
            ->title(__('filament/concerns/email-compose.notifications.queued.title'))
            ->body(__('filament/concerns/email-compose.notifications.queued.body'))
            ->success();

        if ($email->scheduled_for !== null && $email->scheduled_for->isFuture()) {
            $notification
                ->seconds(Config::integer('email-integration.outbox.undo_send_window_seconds'))
                ->actions([
                    Action::make('undo')
                        ->label(__('filament/concerns/email-compose.actions.undo.label'))
                        ->link()
                        ->close()
                        ->dispatchTo(
                            EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                            'undo-queued-send',
                        )
                        ->eventData(['emailId' => (string) $email->getKey()]),
                ]);
        }

        $notification->send();
    }
}
