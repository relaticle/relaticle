<?php

declare(strict_types=1);

namespace App\Listeners\Mcp;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Events\AccessTokenCreated;

/**
 * Bind a freshly minted access token to the team chosen during OAuth consent.
 *
 * The team is picked in ApproveAuthorizationController and stored on the auth
 * code. The access token is minted in a separate POST /oauth/token request with
 * no session, and the `code` parameter there is league's encrypted payload
 * rather than the auth code's id, so the token has to be matched back to its
 * consent by user and client instead.
 */
final class CopyTeamIdToAccessToken
{
    public function handle(AccessTokenCreated $event): void
    {
        if ($event->userId === null) {
            return;
        }

        $teamId = $this->consentedTeamId($event->userId, $event->clientId)
            ?? $this->inheritedTeamId($event->userId, $event->clientId, $event->tokenId);

        if ($teamId === null) {
            return;
        }

        DB::table('oauth_access_tokens')
            ->where('id', $event->tokenId)
            ->update(['team_id' => $teamId]);
    }

    /**
     * The team bound to this client's most recent consent.
     *
     * League revokes the auth code only after the access token is persisted, so
     * during an authorization_code grant the row backing this exchange is still
     * present and unrevoked.
     */
    private function consentedTeamId(string $userId, string $clientId): ?string
    {
        $teamId = DB::table('oauth_auth_codes')
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->whereNotNull('team_id')
            ->latest('expires_at')
            ->value('team_id');

        return is_string($teamId) ? $teamId : null;
    }

    /**
     * The team carried by the token this one replaces.
     *
     * A refresh_token grant has no auth code of its own, and `passport:purge`
     * may already have removed the one from the original consent, so fall back
     * to the binding the previous token for this client was issued with.
     */
    private function inheritedTeamId(string $userId, string $clientId, string $tokenId): ?string
    {
        $teamId = DB::table('oauth_access_tokens')
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('id', '!=', $tokenId)
            ->whereNotNull('team_id')
            ->latest()
            ->value('team_id');

        return is_string($teamId) ? $teamId : null;
    }
}
