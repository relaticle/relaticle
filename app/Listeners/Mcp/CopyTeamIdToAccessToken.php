<?php

declare(strict_types=1);

namespace App\Listeners\Mcp;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;

/**
 * Copy the team_id from a consumed auth code onto the freshly minted access token.
 *
 * The auth code's team_id was set by the custom ApproveAuthorizationController during
 * the user's consent. The access token is created in a separate POST /oauth/token
 * request that has no access to the original session, so we bridge it via the
 * auth code row that's still present in the DB at this point.
 */
final class CopyTeamIdToAccessToken
{
    public function handle(AccessTokenCreated $event): void
    {
        $authCodeId = request()->input('code');

        if (! is_string($authCodeId) || $authCodeId === '') {
            return;
        }

        $authCode = Passport::authCodeModel()::query()->find($authCodeId);

        if (! $authCode || ! is_string($authCode->team_id) || $authCode->team_id === '') {
            return;
        }

        DB::table('oauth_access_tokens')
            ->where('id', $event->tokenId)
            ->update(['team_id' => $authCode->team_id]);
    }
}
