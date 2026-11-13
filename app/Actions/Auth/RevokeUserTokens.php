<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class RevokeUserTokens
{
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();

            $accessTokenIds = DB::table('oauth_access_tokens')
                ->where('user_id', $user->getKey())
                ->pluck('id');

            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $accessTokenIds)
                ->update(['revoked' => true]);

            DB::table('oauth_auth_codes')
                ->where('user_id', $user->getKey())
                ->update(['revoked' => true]);

            DB::table('oauth_device_codes')
                ->where('user_id', $user->getKey())
                ->update(['revoked' => true]);

            DB::table('oauth_access_tokens')
                ->whereIn('id', $accessTokenIds)
                ->update(['revoked' => true]);
        });
    }
}
