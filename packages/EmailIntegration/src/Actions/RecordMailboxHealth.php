<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Enums\MailboxHealth;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class RecordMailboxHealth
{
    public function execute(ConnectedAccount $account, MailboxHealth $health, ?string $reason = null): void
    {
        if ($account->health === $health && $account->health_reason === $reason) {
            return;
        }

        $account->forceFill([
            'health_changed_at' => $account->health === $health ? $account->health_changed_at : now(),
            'health' => $health,
            'health_reason' => $reason,
            'health_notified_at' => $health === MailboxHealth::Healthy ? null : $account->health_notified_at,
        ])->save();
    }
}
