<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class SetDefaultConnectedAccountAction
{
    /**
     * Promote the given account to the user's default within its team. Only one
     * account is default at a time, so the previous default is demoted first
     * (the partial unique index forbids two live defaults for a user/team).
     */
    public function execute(ConnectedAccount $account): void
    {
        if ($account->is_default) {
            return;
        }

        DB::transaction(function () use ($account): void {
            ConnectedAccount::query()
                ->where('user_id', $account->user_id)
                ->where('team_id', $account->team_id)
                ->where('is_default', true)
                ->whereKeyNot($account->getKey())
                ->update(['is_default' => false]);

            $account->update(['is_default' => true]);
        });
    }
}
