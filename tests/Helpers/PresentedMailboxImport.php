<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\Models\Notification;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;

final class PresentedMailboxImport
{
    /** @return array<string, mixed> */
    public static function of(Notification $row): array
    {
        return MailboxHistoryImportCompletedNotification::present($row)->getDatabaseMessage();
    }
}
