<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class DisconnectConnectedAccountAction
{
    public function execute(ConnectedAccount $account): void
    {
        DB::transaction(function () use ($account): void {
            // Account is soft-deleted, so the DB-level cascade on email_signatures never
            // fires. Remove dependent signatures explicitly to avoid orphaned rows whose
            // connectedAccount relation resolves to null on the signatures page.
            $account->signatures()->delete();

            $wasDefault = $account->is_default;

            $account->delete();

            // Never leave the user without a default: hand it to their oldest
            // remaining live account, if any.
            if ($wasDefault) {
                $successor = ConnectedAccount::query()
                    ->where('user_id', $account->user_id)
                    ->where('team_id', $account->team_id)
                    ->whereKeyNot($account->getKey())
                    ->oldest()
                    ->first();

                $successor?->update(['is_default' => true]);
            }
        });
    }
}
