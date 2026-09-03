<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailBlocklist;

final readonly class UpdateConnectedAccountBlocklistAction
{
    /**
     * @param  list<array{type: string, value: string}>  $blocklist
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
                'team_id' => $account->team_id,
                'connected_account_id' => $account->getKey(),
                'type' => $entry['type'],
                'value' => $value,
            ]);
        }
    }
}
