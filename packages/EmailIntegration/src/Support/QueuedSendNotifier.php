<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Js;
use Relaticle\EmailIntegration\Enums\EmailShortcut;
use Relaticle\EmailIntegration\Enums\UndoSendWindow;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\EmailSendHeldNotification;

final readonly class QueuedSendNotifier
{
    public function send(Email $email): void
    {
        $notification = Notification::make()
            ->title(__('filament/concerns/email-compose.notifications.queued.title'))
            ->body(__('filament/concerns/email-compose.notifications.queued.body'))
            ->success();

        if ($email->scheduled_for?->greaterThan(now()->addSeconds(UndoSendWindow::MAX))) {
            $notification->title(__('filament/concerns/email-compose.notifications.scheduled.title'))
                ->body(__('filament/concerns/email-compose.notifications.scheduled.body', [
                    'time' => $email->scheduled_for->setTimezone($email->user->effectiveTimezone())->isoFormat('ddd, MMM D, HH:mm z'),
                ]));
        }

        if ($email->scheduled_for !== null && $email->scheduled_for->isFuture()) {
            $notification
                ->seconds(min(UndoSendWindow::MAX, max(1, (int) ceil(now()->diffInSeconds($email->scheduled_for)))))
                ->actions([
                    Action::make('undo')
                        ->label(__('filament/concerns/email-compose.actions.undo.label'))
                        ->link()
                        ->extraAttributes($this->undoShortcutAttributes())
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

    /** @return array<string, string> */
    private function undoShortcutAttributes(): array
    {
        return [
            'data-email-send-undo' => '',
            'x-data' => e('{
                shortcut: null,
                init() {
                    this.shortcut = this.$store.shortcuts?.register("email-send-undo", {
                        scope: "global",
                        entries: '.Js::from(EmailShortcut::forStore()).',
                        handlers: { undo: () => this.$el.click() },
                        isActive: () => this.$el.isConnected && this.$el.offsetParent !== null,
                    })
                },
                destroy() { this.$store.shortcuts?.unregister("email-send-undo", this.shortcut) },
            }'),
        ];
    }

    public function sendHeld(Email $email, User $user, string $via, int $holdSeconds): void
    {
        $this->notifyNow($user, new EmailSendHeldNotification($email, $via, $holdSeconds));
    }

    public function sendRescheduled(Email $email, User $user, string $via, int $holdSeconds): void
    {
        $this->notifyNow($user, new EmailSendHeldNotification($email, $via, $holdSeconds, rescheduled: true));
    }

    private function notifyNow(User $user, EmailSendHeldNotification $notification): void
    {
        // The queued channel could deliver this after the hold has passed.
        $user->notifyNow($notification);
    }
}
