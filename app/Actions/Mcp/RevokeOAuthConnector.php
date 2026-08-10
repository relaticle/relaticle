<?php

declare(strict_types=1);

namespace App\Actions\Mcp;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Revoke every token a user has granted to one OAuth client (an "AI connector").
 *
 * Passport hands out a long-lived refresh token alongside each access token, so
 * revoking the access token alone would let the client mint a fresh one. Both sides
 * plus any unredeemed auth code go in the same transaction.
 */
final readonly class RevokeOAuthConnector
{
    public function execute(User $user, string $clientId): int
    {
        return DB::transaction(function () use ($user, $clientId): int {
            $tokenIds = DB::table('oauth_access_tokens')
                ->where('user_id', $user->getKey())
                ->where('client_id', $clientId)
                ->pluck('id');

            if ($tokenIds->isEmpty()) {
                return 0;
            }

            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $tokenIds)
                ->update(['revoked' => true]);

            DB::table('oauth_auth_codes')
                ->where('user_id', $user->getKey())
                ->where('client_id', $clientId)
                ->update(['revoked' => true]);

            return DB::table('oauth_access_tokens')
                ->whereIn('id', $tokenIds)
                ->update(['revoked' => true]);
        });
    }
}
