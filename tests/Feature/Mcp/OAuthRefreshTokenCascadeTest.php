<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('cascades refresh-token deletion when the access token is deleted', function (): void {
    $clientId = (string) Str::uuid();
    $accessTokenId = Str::random(80);
    $refreshTokenId = Str::random(80);

    DB::table('oauth_clients')->insert([
        'id' => $clientId,
        'name' => 'Test Client',
        'redirect_uris' => json_encode(['https://example.com']),
        'grant_types' => json_encode(['authorization_code']),
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('oauth_access_tokens')->insert([
        'id' => $accessTokenId,
        'user_id' => null,
        'client_id' => $clientId,
        'name' => 'test',
        'scopes' => '["*"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addHour(),
    ]);

    DB::table('oauth_refresh_tokens')->insert([
        'id' => $refreshTokenId,
        'access_token_id' => $accessTokenId,
        'revoked' => false,
        'expires_at' => now()->addDay(),
    ]);

    DB::table('oauth_access_tokens')->where('id', $accessTokenId)->delete();

    expect(DB::table('oauth_refresh_tokens')->where('id', $refreshTokenId)->exists())
        ->toBeFalse();
});
