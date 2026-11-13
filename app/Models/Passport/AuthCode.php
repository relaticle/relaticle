<?php

declare(strict_types=1);

namespace App\Models\Passport;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Laravel\Passport\AuthCode as BaseAuthCode;

/**
 * Custom Passport AuthCode that binds a workspace selected during the OAuth consent.
 *
 * The workspace_id is stashed in the session by the custom ApproveAuthorizationController
 * (POST /oauth/authorize) and read here when Passport persists the auth code row.
 */
#[Fillable([
    'id',
    'user_id',
    'client_id',
    'scopes',
    'revoked',
    'expires_at',
    'workspace_id',
])]
final class AuthCode extends BaseAuthCode
{
    protected static function booted(): void
    {
        self::creating(function (self $code): void {
            $consentedWorkspaceId = session()->pull('mcp.oauth.workspace_id');

            $code->workspace_id = is_string($consentedWorkspaceId) && $consentedWorkspaceId !== ''
                ? $consentedWorkspaceId
                : null;
        });
    }
}
