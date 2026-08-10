<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Data\ConnectAccountData;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class ConnectAccountAction
{
    public function execute(ConnectAccountData $data): ConnectedAccount
    {
        return DB::transaction(function () use ($data): ConnectedAccount {
            // Match against trashed rows too: the unique index spans soft-deleted
            // records, so a previously disconnected account must be reused and
            // restored rather than inserted again.
            $values = [
                'display_name' => $data->displayName,
                'provider_account_id' => $data->providerAccountId,
                'access_token' => $data->accessToken,
                'token_expires_at' => $data->tokenExpiresAt,
                'status' => 'active',
                'last_error' => null,
                'auto_create_companies' => true,
                'contact_creation_mode' => ContactCreationMode::Bidirectional,
                'capabilities' => [
                    'email' => true,
                    'calendar' => $data->hasCalendar,
                ],
            ];

            // On re-consent the provider often returns no refresh token (it is only
            // issued on first authorization). Overwriting with null would strip the
            // stored working token and leave the account permanently unable to refresh.
            // Only write it when the provider actually returned one.
            if ($data->refreshToken !== null) {
                $values['refresh_token'] = $data->refreshToken;
            }

            $account = ConnectedAccount::withTrashed()->updateOrCreate(
                [
                    'user_id' => $data->userId,
                    'provider' => $data->provider,
                    'email_address' => $data->emailAddress,
                    'team_id' => $data->teamId,
                ],
                $values
            );

            if ($account->trashed()) {
                $account->restore();
            }

            // The first account a user connects becomes their default. This also
            // re-promotes a fresh connection when a previous default was removed,
            // so the user is never left without one.
            $hasDefault = ConnectedAccount::query()
                ->where('user_id', $data->userId)
                ->where('team_id', $data->teamId)
                ->where('is_default', true)
                ->whereKeyNot($account->getKey())
                ->exists();

            if (! $hasDefault && ! $account->is_default) {
                $account->update(['is_default' => true]);
            }

            return $account;
        });
    }
}
