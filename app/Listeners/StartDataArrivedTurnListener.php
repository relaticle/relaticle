<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\OpenDataArrivedTurn;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Notifications\Events\NotificationSent;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\ImportWizard\Enums\ImportOutcome;
use Relaticle\ImportWizard\Notifications\ImportFinishedNotification;

final readonly class StartDataArrivedTurnListener
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        $notification = $event->notification;

        if ($notification instanceof ImportFinishedNotification && $this->importBroughtRecords($notification)) {
            $this->open($event->notifiable, $notification->workspace(), "import:{$notification->subject()->getKey()}");
        }

        if ($notification instanceof MailboxHistoryImportCompletedNotification && $this->mailboxBroughtMessages($notification)) {
            $this->open($event->notifiable, $notification->workspace(), 'mailbox:'.($notification->batchId ?? $notification->account->getKey()));
        }
    }

    private function open(mixed $notifiable, Workspace $workspace, string $sourceKey): void
    {
        if (! $notifiable instanceof User || (string) $notifiable->getKey() !== (string) $workspace->user_id) {
            return;
        }

        dispatch(new OpenDataArrivedTurn((string) $workspace->getKey(), $sourceKey));
    }

    private function importBroughtRecords(ImportFinishedNotification $notification): bool
    {
        $facts = $notification->facts();

        return $facts['outcome'] !== ImportOutcome::Failed->value && (int) $facts['created'] > 0;
    }

    private function mailboxBroughtMessages(MailboxHistoryImportCompletedNotification $notification): bool
    {
        return $notification->importedEmailCount > 0 || $notification->importedCalendarCount > 0;
    }
}
