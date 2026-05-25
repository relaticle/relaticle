<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class UpdateConnectedAccountSettingsAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(ConnectedAccount $account, array $data): void
    {
        $account->update([
            'sync_inbox' => $data['sync_inbox'],
            'sync_sent' => $data['sync_sent'],
            'contact_creation_mode' => $data['contact_creation_mode'],
            'auto_create_companies' => $data['auto_create_companies'],
            'hourly_send_limit' => filled($data['hourly_send_limit'] ?? null) ? (int) $data['hourly_send_limit'] : null,
            'daily_send_limit' => filled($data['daily_send_limit'] ?? null) ? (int) $data['daily_send_limit'] : null,
        ]);
    }
}
