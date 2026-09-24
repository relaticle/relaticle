<?php

declare(strict_types=1);

namespace App\Models\Passport;

use Illuminate\Support\Facades\DB;
use Laravel\Passport\AuthCode as BaseAuthCode;

/**
 * Custom Passport AuthCode that binds a workspace selected during the OAuth consent.
 *
 * The workspace_id is stashed in the session by the custom ApproveAuthorizationController
 * (POST /oauth/authorize) and read here when Passport persists the auth code row.
 */
final class AuthCode extends BaseAuthCode
{
    protected $fillable = [
        'id',
        'user_id',
        'client_id',
        'scopes',
        'revoked',
        'expires_at',
        'workspace_id',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $code): void {
            $consentedWorkspaceId = session()->pull('mcp.oauth.workspace_id');

            $code->workspace_id = filled($consentedWorkspaceId) ? $consentedWorkspaceId : $code->grantedWorkspaceId();
        });
    }

    // Passport skips consent, and so the workspace picker, while the user holds an
    // active token for the client; the code then inherits that token's workspace.
    private function grantedWorkspaceId(): ?string
    {
        $workspaceId = DB::table('oauth_access_tokens')
            ->where('user_id', $this->user_id)
            ->where('client_id', $this->client_id)
            ->where('revoked', false)
            ->where('expires_at', '>', now())
            ->whereNotNull('workspace_id')
            ->latest()
            ->value('workspace_id');

        return is_string($workspaceId) ? $workspaceId : null;
    }
}
