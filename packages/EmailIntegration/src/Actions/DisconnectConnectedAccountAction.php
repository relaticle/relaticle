<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\ProviderGrantRevoker;

final readonly class DisconnectConnectedAccountAction
{
    public function __construct(
        private StopCalendarPushChannelAction $stopCalendarPushChannel,
        private ProviderGrantRevoker $grantRevoker,
    ) {}

    public function execute(ConnectedAccount $account): void
    {
        $grant = null;

        DB::transaction(function () use ($account, &$grant): void {
            $this->stopCalendarPushChannel->execute($account);
            $grant = $account->refresh_token ?? $account->access_token;
            // Account is soft-deleted, so the DB-level cascade on email_signatures never
            // fires. Remove dependent signatures and blocklist entries explicitly to avoid
            // orphaned rows whose connectedAccount relation resolves to null.
            $account->signatures()->delete();
            $account->blocklist()->delete();

            $wasDefault = $account->is_default;

            $account->forceFill([
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
            ])->save();

            $account->delete();

            // Never leave the user without a default: hand it to their oldest
            // remaining live account, if any.
            if ($wasDefault) {
                $successor = ConnectedAccount::query()
                    ->where('user_id', $account->user_id)
                    ->where('workspace_id', $account->workspace_id)
                    ->whereKeyNot($account->getKey())
                    ->oldest()
                    ->first();

                $successor?->update(['is_default' => true]);
            }
        });

        $this->grantRevoker->revoke($account, $grant);

        Artisan::queue('email:backfill-conversations', ['--force' => true, '--workspace' => $account->workspace_id])
            ->onQueue('imports')
            ->afterCommit();
    }
}
