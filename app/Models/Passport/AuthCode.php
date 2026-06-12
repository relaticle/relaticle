<?php

declare(strict_types=1);

namespace App\Models\Passport;

use Laravel\Passport\AuthCode as BaseAuthCode;

/**
 * Custom Passport AuthCode that binds a team selected during the OAuth consent.
 *
 * The team_id is stashed in the session by the custom ApproveAuthorizationController
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
        'team_id',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $code): void {
            $teamId = session()->pull('mcp.oauth.team_id');

            if (is_string($teamId) && $teamId !== '') {
                $code->team_id = $teamId;
            }
        });
    }
}
