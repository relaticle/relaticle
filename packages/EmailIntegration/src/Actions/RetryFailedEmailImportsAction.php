<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\FailedStoreEmailImportService;
use RuntimeException;

final readonly class RetryFailedEmailImportsAction
{
    public function __construct(
        private FailedStoreEmailImportService $failedImports,
    ) {}

    public function execute(ConnectedAccount $account): int
    {
        $count = $this->failedImports->retryAll($account);

        throw_if($count === 0, RuntimeException::class, 'No failed email imports to retry for this account.');

        $account->update([
            'status' => EmailAccountStatus::ACTIVE,
            'last_error' => null,
        ]);

        return $count;
    }
}
