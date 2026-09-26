<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBlocklist;

final readonly class UpdateConnectedAccountBlocklistAction
{
    /**
     * @param  list<array{type: string, value: string, include_subdomains?: bool}>  $blocklist
     */
    public function execute(ConnectedAccount $account, array $blocklist): void
    {
        EmailBlocklist::query()
            ->where('connected_account_id', $account->getKey())
            ->delete();

        foreach ($blocklist as $entry) {
            $value = strtolower(trim((string) $entry['value']));

            if ($value === '') {
                continue;
            }

            EmailBlocklist::query()->create([
                'user_id' => $account->user_id,
                'workspace_id' => $account->workspace_id,
                'connected_account_id' => $account->getKey(),
                'type' => $entry['type'],
                'value' => $value,
                'include_subdomains' => (bool) ($entry['include_subdomains'] ?? false),
            ]);
        }
    }
}
