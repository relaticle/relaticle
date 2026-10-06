<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

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

    public function sendHeld(Email $email, User $user, Workspace $workspace, string $via, int $holdSeconds): void
    {
        $notice = Notification::make()
            ->title(__('filament/concerns/email-compose.notifications.held.title', ['via' => e($via)]))
            ->body(__('filament/concerns/email-compose.notifications.held.body', [
                'subject' => e((string) $email->subject),
                'recipients' => $this->recipients($email),
                'minutes' => trans_choice('filament/concerns/email-compose.notifications.held.minutes', max(1, intdiv($holdSeconds, 60))),
                'workspace' => e($workspace->name),
            ]))
            ->warning()
            ->actions([
                Action::make('cancelSend')
                    ->label(__('filament/concerns/email-compose.actions.cancel_send.label'))
                    ->button()
                    ->markAsRead()
                    ->dispatchTo(
                        EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                        'undo-queued-send',
                    )
                    ->eventData(['emailId' => (string) $email->getKey()]),
            ])
            ->toDatabase();

        // The queued channel could deliver this after the hold has passed.
        $user->notifyNow($notice);
    }

    private function recipients(Email $email): string
    {
        $order = [EmailParticipantRole::TO, EmailParticipantRole::CC, EmailParticipantRole::BCC];

        return $email->participants()
            ->whereIn('role', $order)
            ->get()
            ->sortBy(fn (EmailParticipant $participant): int|false => array_search($participant->role, $order, true))
            ->map(fn (EmailParticipant $participant): string => e($participant->email_address))
            ->join(', ');
    }
}
