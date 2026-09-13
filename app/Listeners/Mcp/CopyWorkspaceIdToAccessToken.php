<?php

declare(strict_types=1);

namespace App\Listeners\Mcp;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\Events\AccessTokenCreated;

/**
 * Bind a freshly minted access token to the workspace chosen during OAuth consent.
 *
 * The workspace is picked in ApproveAuthorizationController and stored on the auth
 * code. The access token is minted in a separate POST /oauth/token request with
 * no session, and the `code` parameter there is league's encrypted payload
 * rather than the auth code's id, so the token has to be matched back to its
 * consent by user and client instead.
 */
final class CopyWorkspaceIdToAccessToken
{
    public function handle(AccessTokenCreated $event): void
    {
        if ($event->userId === null) {
            return;
        }

        $workspaceId = $this->consentedWorkspaceId($event->userId, $event->clientId)
            ?? $this->inheritedWorkspaceId($event->userId, $event->clientId, $event->tokenId);

        if ($workspaceId === null) {
            return;
        }

        DB::table('oauth_access_tokens')
            ->where('id', $event->tokenId)
            ->update(['workspace_id' => $workspaceId]);
    }

    /**
     * The workspace bound to this client's most recent consent.
     *
     * League revokes the auth code only after the access token is persisted, so
     * during an authorization_code grant the row backing this exchange is still
     * present and unrevoked.
     */
    private function consentedWorkspaceId(string $userId, string $clientId): ?string
    {
        $workspaceId = DB::table('oauth_auth_codes')
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->whereNotNull('workspace_id')
            ->latest('expires_at')
            ->value('workspace_id');

        return is_string($workspaceId) ? $workspaceId : null;
    }

    /**
     * The workspace carried by the token this one replaces.
     *
     * A refresh_token grant has no auth code of its own, and `passport:purge`
     * may already have removed the one from the original consent, so fall back
     * to the binding the previous token for this client was issued with.
     */
    private function inheritedWorkspaceId(string $userId, string $clientId, string $tokenId): ?string
    {
        $workspaceId = DB::table('oauth_access_tokens')
            ->where('user_id', $userId)
            ->where('client_id', $clientId)
            ->where('id', '!=', $tokenId)
            ->whereNotNull('workspace_id')
            ->latest()
            ->value('workspace_id');

        return is_string($workspaceId) ? $workspaceId : null;
    }
}
