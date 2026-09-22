<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Data\ConnectAccountData;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\MailboxHistoryImportService;

final readonly class ConnectAccountAction
{
    public function __construct(
        private StartMailboxHistoryImportAction $startMailboxHistoryImport,
        private MailboxHistoryImportService $mailboxHistoryImport,
    ) {}

    public function execute(ConnectAccountData $data): ConnectedAccount
    {
        $needsHistoryImport = false;

        $account = DB::transaction(function () use ($data, &$needsHistoryImport): ConnectedAccount {
            // Match against trashed rows too: the unique index spans soft-deleted
            // records, so a previously disconnected account must be reused and
            // restored rather than inserted again. Uniqueness is per workspace
            // (user, team, provider, email), so the same mailbox can exist on
            // another team without colliding.
            $lookup = [
                'user_id' => $data->userId,
                'provider' => $data->provider,
                'email_address' => $data->emailAddress,
                'workspace_id' => $data->teamId,
            ];

            $existing = ConnectedAccount::withTrashed()->where($lookup)->first();

            $resumeStoppedImport = $existing instanceof ConnectedAccount
                && ! $existing->trashed()
                && $existing->sync_cursor === null
                && ! $this->mailboxHistoryImport->isRunning($existing);

            $importStillDraining = $existing instanceof ConnectedAccount
                && ! $existing->trashed()
                && $this->mailboxHistoryImport->isRunning($existing);

            $restartImportOnLiveReconnect = $existing instanceof ConnectedAccount
                && ! $existing->trashed()
                && $existing->sync_cursor !== null
                && ! $this->mailboxHistoryImport->isRunning($existing);

            $values = [
                'display_name' => $data->displayName,
                'provider_account_id' => $data->providerAccountId,
                'access_token' => $data->accessToken,
                'token_expires_at' => $data->tokenExpiresAt,
                'capabilities' => [
                    'email' => true,
                    'send' => $data->hasSend,
                    'calendar' => $data->hasCalendar,
                ],
            ];

            if (! $importStillDraining) {
                $values['status'] = EmailAccountStatus::ACTIVE;
                $values['last_error'] = null;
            }

            // On re-consent the provider often returns no refresh token (it is only
            // issued on first authorization). Overwriting with null would strip the
            // stored working token and leave the account permanently unable to refresh.
            // Only write it when the provider actually returned one.
            if ($data->refreshToken !== null) {
                $values['refresh_token'] = $data->refreshToken;
            }

            $account = ConnectedAccount::withTrashed()->updateOrCreate($lookup, $values);

            $needsHistoryImport = ! $importStillDraining
                && (
                    $account->wasRecentlyCreated
                    || $account->trashed()
                    || $resumeStoppedImport
                    || $restartImportOnLiveReconnect
                );

            if (! $account->trashed() && ($resumeStoppedImport || $restartImportOnLiveReconnect)) {
                $account->update(['history_import_batch_id' => null]);
            }

            if ($account->trashed()) {
                // Disconnect promotes a successor but leaves is_default set while trashed.
                // Demote before restore so the live-default unique index is not violated.
                if ($account->is_default) {
                    $hasOtherDefault = ConnectedAccount::query()
                        ->where('user_id', $data->userId)
                        ->where('workspace_id', $data->teamId)
                        ->where('is_default', true)
                        ->whereKeyNot($account->getKey())
                        ->exists();

                    if ($hasOtherDefault) {
                        $account->update(['is_default' => false]);
                    }
                }

                $account->restore();
                $account->update(['history_import_batch_id' => null]);
            }

            // The first account a user connects becomes their default. This also
            // re-promotes a fresh connection when a previous default was removed,
            // so the user is never left without one.
            $hasDefault = ConnectedAccount::query()
                ->where('user_id', $data->userId)
                ->where('workspace_id', $data->teamId)
                ->where('is_default', true)
                ->whereKeyNot($account->getKey())
                ->exists();

            if (! $hasDefault && ! $account->is_default) {
                $account->update(['is_default' => true]);
            }

            return $account;
        });

        if ($needsHistoryImport) {
            $this->startMailboxHistoryImport->execute($account);
        }

        return $account;
    }
}
