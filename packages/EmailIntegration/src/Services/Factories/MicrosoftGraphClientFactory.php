<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use RuntimeException;
use Throwable;

final readonly class MicrosoftGraphClientFactory
{
    public function make(ConnectedAccount $account): PendingRequest
    {
        $this->refreshIfExpired($account);

        return Http::withToken((string) $account->access_token)
            ->withHeaders(['Prefer' => 'IdType="ImmutableId"'])
            ->acceptJson()
            ->asJson()
            ->baseUrl('https://graph.microsoft.com/v1.0')
            ->retry([200, 1000], when: $this->isTransientReadFailure(...), throw: false);
    }

    // Reads only: Graph can answer 5xx after accepting a sendMail, and a retry would send it twice.
    private function isTransientReadFailure(Throwable $exception, PendingRequest $request, ?string $method): bool
    {
        if ($method !== 'GET') {
            return false;
        }

        return $exception instanceof ConnectionException
            || ($exception instanceof RequestException && $exception->response->serverError());
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

        // Entra names an AADSTS code in its transient errors too, which would read as a lost grant.
        $response->throwIfServerError();

        throw_unless($response->successful(), RuntimeException::class, "Microsoft token refresh failed: {$response->body()}");

        $payload = $response->json();

        $this->assertStillConnected($account);

        $account->update([
            'access_token' => (string) $payload['access_token'],
            'refresh_token' => $payload['refresh_token'] ?? $account->refresh_token,
            'token_expires_at' => now()->addSeconds((int) ($payload['expires_in'] ?? 3600)),
        ]);
    }

    private function assertStillConnected(ConnectedAccount $account): void
    {
        throw_unless(
            ConnectedAccount::query()->whereKey($account->getKey())->exists(),
            RuntimeException::class,
            'invalid_grant: the mailbox was disconnected while its token was refreshing',
        );
    }
}
