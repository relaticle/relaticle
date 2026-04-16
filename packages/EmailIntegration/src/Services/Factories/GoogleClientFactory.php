<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Google\Client as GoogleClient;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

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
            $newToken = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);

            $account->update([
                'access_token' => $newToken['access_token'],
                'token_expires_at' => now()->addSeconds($newToken['expires_in']),
            ]);
        }

        return $client;
    }
}
