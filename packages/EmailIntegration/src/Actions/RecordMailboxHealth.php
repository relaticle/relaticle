<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Enums\MailboxHealth;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class RecordMailboxHealth
{
    // A row the watchdog has never assessed predates health tracking. A failure found on it
    // keeps a null health_changed_at, so no notice ever goes out for that old failure.
    public function execute(ConnectedAccount $account, MailboxHealth $health, ?string $reason = null): void
    {
        $neverAssessed = $account->health_changed_at === null && $account->health === MailboxHealth::Healthy;

        if (! $neverAssessed && $account->health === $health && $account->health_reason === $reason) {
            return;
        }

        $oldFailure = $neverAssessed && $health !== MailboxHealth::Healthy;

        $account->forceFill([
            'health_changed_at' => match (true) {
                $oldFailure => null,
                $neverAssessed, $account->health !== $health => now(),
                default => $account->health_changed_at,
            },
            'health' => $health,
            'health_reason' => $reason,
            'health_notified_at' => match (true) {
                $health === MailboxHealth::Healthy => null,
                $oldFailure => now(),
                default => $account->health_notified_at,
            },
        ])->save();
    }
}
