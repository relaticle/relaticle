<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;

mutates(MicrosoftGraphClientFactory::class);

beforeEach(function (): void {
    config()->set('services.azure.client_id', 'azure-client-id');
    config()->set('services.azure.client_secret', 'azure-client-secret');
    config()->set('services.azure.tenant', 'common');

    // Prevent the ConnectedAccountObserver from running InitialEmailSyncJob synchronously
    // during account creation, which would issue unfaked Graph requests.
    Bus::fake();
});

it('returns a pre-authorized PendingRequest with the access token', function (): void {
    Http::fake();

    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'access_token' => 'still-valid-token',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
        ]);

    resolve(MicrosoftGraphClientFactory::class)
        ->make($account)
        ->get('https://graph.microsoft.com/v1.0/me');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer still-valid-token')
        && $request->hasHeader('Prefer', 'IdType="ImmutableId"'));
});

it('asks Graph for immutable message ids on every client request', function (): void {
    Http::fake();

    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'access_token' => 'still-valid-token',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
        ]);

    resolve(MicrosoftGraphClientFactory::class)
        ->make($account)
        ->get('/me/messages/AAMkAGI1');

    Http::assertSent(fn ($request) => $request->hasHeader('Prefer', 'IdType="ImmutableId"'));
});

it('refreshes and persists a new access token when expired', function (): void {
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fresh-token',
            'refresh_token' => 'rotated-refresh',
            'expires_in' => 3600,
        ]),
        'https://graph.microsoft.com/*' => Http::response(['ok' => true]),
    ]);

    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->subMinute(),
        ]);

    resolve(MicrosoftGraphClientFactory::class)
        ->make($account)
        ->get('https://graph.microsoft.com/v1.0/me');

    $fresh = $account->refresh();
    expect($fresh->access_token)->toBe('fresh-token')
        ->and($fresh->refresh_token)->toBe('rotated-refresh')
        ->and($fresh->token_expires_at->isFuture())->toBeTrue();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'login.microsoftonline.com'));
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fresh-token'));
});

it('refreshes when token_expires_at is null', function (): void {
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response([
            'access_token' => 'fresh-token',
            'expires_in' => 3600,
        ]),
        'https://graph.microsoft.com/*' => Http::response(['ok' => true]),
    ]);

    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'refresh_token' => 'refresh-1',
            'token_expires_at' => null,
        ]);

    resolve(MicrosoftGraphClientFactory::class)
        ->make($account)
        ->get('https://graph.microsoft.com/v1.0/me');

    expect($account->refresh()->access_token)->toBe('fresh-token');
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'login.microsoftonline.com'));
});

it('fails with an auth-error marker instead of POSTing an empty refresh token', function (): void {
    Http::fake();

    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'access_token' => 'expired-token',
            'refresh_token' => null,
            'token_expires_at' => now()->subMinute(),
        ]);

    // invalid_grant is recognised by DetectsAuthErrors, so the sync job flags the
    // account for re-authentication rather than retrying to death.
    expect(fn () => resolve(MicrosoftGraphClientFactory::class)->make($account))
        ->toThrow(RuntimeException::class, 'invalid_grant');

    // No token request is made when there is nothing to refresh with.
    Http::assertNothingSent();
});

it('throws RuntimeException when the refresh endpoint returns an error', function (): void {
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Refresh token expired',
        ], 400),
    ]);

    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->subMinute(),
        ]);

    expect(fn () => resolve(MicrosoftGraphClientFactory::class)->make($account))
        ->toThrow(RuntimeException::class);
});

it('does not store refreshed tokens on a mailbox that was disconnected meanwhile', function (): void {
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response([
            'access_token' => 'minted-after-disconnect',
            'refresh_token' => 'rotated-after-disconnect',
            'expires_in' => 3600,
        ]),
    ]);

    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->subMinute(),
        ]);

    ConnectedAccount::query()->whereKey($account->getKey())->update(['deleted_at' => now(), 'access_token' => null, 'refresh_token' => null]);

    expect(fn () => resolve(MicrosoftGraphClientFactory::class)->make($account))
        ->toThrow(RuntimeException::class, 'invalid_grant');

    expect(ConnectedAccount::withTrashed()->findOrFail($account->getKey())->refresh_token)->toBeNull();
});

function graphClientForActiveMailbox(): PendingRequest
{
    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'access_token' => 'still-valid-token',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
        ]);

    return resolve(MicrosoftGraphClientFactory::class)->make($account);
}

it('retries a read that Graph answers with a gateway error', function (): void {
    Http::fake([
        'graph.microsoft.com/*' => Http::sequence()
            ->push(['error' => ['code' => 'UnknownError']], 502)
            ->push(['id' => 'inbox-id']),
    ]);

    $response = graphClientForActiveMailbox()->get('/me/mailFolders/inbox');

    expect($response->json('id'))->toBe('inbox-id');
    Http::assertSentCount(2);
});

it('hands back the gateway error when Graph stays down', function (): void {
    Http::fake(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'UnknownError']], 502)]);

    $response = graphClientForActiveMailbox()->get('/me/mailFolders/inbox');

    expect($response->status())->toBe(502);
    Http::assertSentCount(3);
});

it('returns a missing folder as a response without retrying', function (): void {
    Http::fake(['graph.microsoft.com/*' => Http::response([], 404)]);

    $response = graphClientForActiveMailbox()->get('/me/mailFolders/junkemail');

    expect($response->status())->toBe(404);
    Http::assertSentCount(1);
});

it('never retries a write, so a send is not repeated', function (): void {
    Http::fake(['graph.microsoft.com/*' => Http::response([], 502)]);

    $response = graphClientForActiveMailbox()->post('/me/sendMail', ['message' => []]);

    expect($response->status())->toBe(502);
    Http::assertSentCount(1);
});

it('raises an outage, not a lost grant, when the token endpoint answers a server error', function (): void {
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response([
            'error' => 'temporarily_unavailable',
            'error_description' => 'AADSTS90033: A transient error has occurred. Please try again.',
        ], 503),
    ]);

    $user = User::factory()->withWorkspace()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'workspace_id' => $user->currentWorkspace->getKey(),
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->subMinute(),
        ]);

    expect(fn () => resolve(MicrosoftGraphClientFactory::class)->make($account))
        ->toThrow(fn (RequestException $exception) => expect($exception->response->status())->toBe(503));
});
