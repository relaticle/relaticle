<?php

declare(strict_types=1);

namespace App\Queries\Tokens;

use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class LiveAgentCredentialsQuery
{
    public function oauthClientIds(string $userId): Builder
    {
        return $this->liveAccessTokens($userId)->select('client_id');
    }

    public function accessTokenIds(string $userId): Builder
    {
        return $this->liveAccessTokens($userId)->select('id');
    }

    public function isLive(string $userId, ?string $oauthClientId, ?int $personalAccessTokenId): bool
    {
        if ($personalAccessTokenId !== null && ! $this->holdsAccessToken($userId, $personalAccessTokenId)) {
            return false;
        }

        if ($oauthClientId !== null) {
            return $this->oauthClientIds($userId)->where('client_id', $oauthClientId)->exists();
        }

        return true;
    }

    // A connector whose access token expired is still live while its refresh token can mint a new one.
    private function liveAccessTokens(string $userId): Builder
    {
        return DB::table('oauth_access_tokens')
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->where(fn (Builder $live): Builder => $live
                ->where('expires_at', '>', now())
                ->orWhereExists(fn (Builder $refresh): Builder => $refresh
                    ->from('oauth_refresh_tokens')
                    ->whereColumn('oauth_refresh_tokens.access_token_id', 'oauth_access_tokens.id')
                    ->where('oauth_refresh_tokens.revoked', false)
                    ->where('oauth_refresh_tokens.expires_at', '>', now())));
    }

    private function holdsAccessToken(string $userId, int $personalAccessTokenId): bool
    {
        return PersonalAccessToken::query()
            ->whereKey($personalAccessTokenId)
            ->where('tokenable_id', $userId)
            ->where(fn (EloquentBuilder $query): EloquentBuilder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->exists();
    }
}
