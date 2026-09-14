<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use RuntimeException;

final readonly class MicrosoftGraphClientFactory
{
    public function make(ConnectedAccount $account): PendingRequest
    {
        $this->refreshIfExpired($account);

        return Http::withToken((string) $account->access_token)
            ->acceptJson()
            ->asJson()
            ->baseUrl('https://graph.microsoft.com/v1.0');
    }

    private function refreshIfExpired(ConnectedAccount $account): void
    {
        if ($account->token_expires_at !== null && $account->token_expires_at->isAfter(now()->addMinute())) {
            return;
        }

        // No refresh token means the account can never silently re-mint an access
        // token. Fail with an auth-error marker (DetectsAuthErrors recognises
        // "invalid_grant") so the sync job flags the account for re-authentication
        // instead of POSTing an empty refresh_token and retrying to death.
        throw_if(
            blank($account->refresh_token),
            RuntimeException::class,
            'Microsoft token refresh failed: invalid_grant (no refresh token stored; account must re-authenticate)'
        );

        $tenant = (string) (config('services.azure.tenant') ?: 'common');

        $response = Http::asForm()->post(
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $account->refresh_token,
                'client_id' => (string) config('services.azure.client_id'),
                'client_secret' => (string) config('services.azure.client_secret'),
                'scope' => 'offline_access https://graph.microsoft.com/.default',
            ]
        );

        throw_unless($response->successful(), RuntimeException::class, "Microsoft token refresh failed: {$response->body()}");

        $payload = $response->json();

        $account->update([
            'access_token' => (string) $payload['access_token'],
            'refresh_token' => $payload['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($payload['expires_in'] ?? 3600)),
        ]);
    }
}
