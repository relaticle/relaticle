<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class DisconnectConnectedAccountAction
{
    public function execute(ConnectedAccount $account): void
    {
        $account->delete();
    }
}
