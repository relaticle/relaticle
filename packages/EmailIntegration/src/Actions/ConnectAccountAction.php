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
            $account = ConnectedAccount::withTrashed()->updateOrCreate(
                [
                    'user_id' => $data->userId,
                    'provider' => $data->provider,
                    'email_address' => $data->emailAddress,
                    'team_id' => $data->teamId,
                ],
                [
                    'display_name' => $data->displayName,
                    'provider_account_id' => $data->providerAccountId,
                    'access_token' => $data->accessToken,
                    'refresh_token' => $data->refreshToken,
                    'token_expires_at' => $data->tokenExpiresAt,
                    'status' => 'active',
                    'last_error' => null,
                    'auto_create_companies' => true,
                    'contact_creation_mode' => ContactCreationMode::All,
                    'capabilities' => [
                        'email' => true,
                        'calendar' => $data->hasCalendar,
                    ],
                ]
            );

            if ($account->trashed()) {
                $account->restore();
            }

            return $account;
        });
    }
}
