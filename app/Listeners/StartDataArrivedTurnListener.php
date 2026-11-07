<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Onboarding\StartDataArrivedTurn;
use Illuminate\Notifications\Events\NotificationSent;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\ImportWizard\Enums\ImportOutcome;
use Relaticle\ImportWizard\Notifications\ImportFinishedNotification;

final readonly class StartDataArrivedTurnListener
{
    public function __construct(private StartDataArrivedTurn $action) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return;
        }

        $notification = $event->notification;

        if ($notification instanceof ImportFinishedNotification && $this->importBroughtRecords($notification)) {
            $this->action->execute($notification->workspace(), "import:{$notification->subject()->getKey()}");
        }

        if ($notification instanceof MailboxHistoryImportCompletedNotification && $this->mailboxBroughtMessages($notification)) {
            $this->action->execute($notification->workspace(), 'mailbox:'.($notification->batchId ?? $notification->account->getKey()));
        }
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
