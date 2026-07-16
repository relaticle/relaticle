<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Google\Client as GoogleClient;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use RuntimeException;

final readonly class GoogleClientFactory
{
    public function make(ConnectedAccount $account): GoogleClient
    {
        $client = new GoogleClient;

        $client->setClientId(config('services.gmail.client_id'));
        $client->setClientSecret(config('services.gmail.client_secret'));

        // Seconds until expiry, clamped to 0 so an already-expired token reports
        // expires_in=0 (not a positive magnitude via abs()) and trips the refresh below.
        $expiresIn = $account->token_expires_at
            ? max(0, (int) round(now()->diffInSeconds($account->token_expires_at, false)))
            : 0;

        $client->setAccessToken([
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_in' => $expiresIn,
            'created' => time(),
        ]);

        if ($client->isAccessTokenExpired()) {
            if (! $account->refresh_token) {
                // No refresh token to recover with — surface as an auth error so the
                // sync job flips the account to REAUTH_REQUIRED.
                throw new RuntimeException("invalid_grant: missing refresh token for account {$account->getKey()}");
            }

            $newToken = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);

            // The Google client returns an array with an `error` key (e.g. invalid_grant)
            // instead of throwing when the grant is revoked; persisting it would store a
            // null access token, so fail loudly instead.
            if (isset($newToken['error'])) {
                throw new RuntimeException(
                    $newToken['error'].': '.($newToken['error_description'] ?? 'token refresh failed')
                );
            }

            $account->update(array_filter([
                'access_token' => $newToken['access_token'] ?? null,
                // Google may rotate the refresh token; keep the existing one when absent.
                'refresh_token' => $newToken['refresh_token'] ?? null,
                'token_expires_at' => now()->addSeconds((int) ($newToken['expires_in'] ?? 3600)),
            ], static fn (mixed $value): bool => $value !== null));
        }

        return $client;
    }
}
