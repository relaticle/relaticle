<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

final readonly class RecordMailboxHistoryImportStoreFailureAction
{
    public function __construct(
        private MailboxHistoryImportService $mailboxHistoryImport,
    ) {}

    public function execute(ConnectedAccount $account, string $historyImportBatchId): void
    {
        if ($account->history_import_batch_id !== $historyImportBatchId) {
            return;
        }

        $this->mailboxHistoryImport->recordFailureGeneration($historyImportBatchId);
    }
}
