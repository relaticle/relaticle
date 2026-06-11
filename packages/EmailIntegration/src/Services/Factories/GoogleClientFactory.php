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

        $expiresIn = $account->token_expires_at
            ? (int) round(abs($account->token_expires_at->diffInSeconds(now())))
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
                    (string) $newToken['error'].': '.(string) ($newToken['error_description'] ?? 'token refresh failed')
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
