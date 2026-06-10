<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class DisconnectConnectedAccountAction
{
    public function execute(ConnectedAccount $account): void
    {
        // Account is soft-deleted, so the DB-level cascade on email_signatures never
        // fires. Remove dependent signatures explicitly to avoid orphaned rows whose
        // connectedAccount relation resolves to null on the signatures page.
        $account->signatures()->delete();

        $account->delete();
    }
}
