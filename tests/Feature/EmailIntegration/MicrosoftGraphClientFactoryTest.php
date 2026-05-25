<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Factories\MicrosoftGraphClientFactory;

mutates(MicrosoftGraphClientFactory::class);

beforeEach(function (): void {
    config()->set('services.azure.client_id', 'azure-client-id');
    config()->set('services.azure.client_secret', 'azure-client-secret');
    config()->set('services.azure.tenant', 'common');
});

it('returns a pre-authorized PendingRequest with the access token', function (): void {
    Http::fake();

    $user = User::factory()->withTeam()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'team_id' => $user->currentTeam->getKey(),
            'access_token' => 'still-valid-token',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
        ]);

    resolve(MicrosoftGraphClientFactory::class)
        ->make($account)
        ->get('https://graph.microsoft.com/v1.0/me');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer still-valid-token'));
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

    $user = User::factory()->withTeam()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'team_id' => $user->currentTeam->getKey(),
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

    $user = User::factory()->withTeam()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'team_id' => $user->currentTeam->getKey(),
            'refresh_token' => 'refresh-1',
            'token_expires_at' => null,
        ]);

    resolve(MicrosoftGraphClientFactory::class)
        ->make($account)
        ->get('https://graph.microsoft.com/v1.0/me');

    expect($account->refresh()->access_token)->toBe('fresh-token');
    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'login.microsoftonline.com'));
});

it('throws RuntimeException when the refresh endpoint returns an error', function (): void {
    Http::fake([
        'https://login.microsoftonline.com/*' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Refresh token expired',
        ], 400),
    ]);

    $user = User::factory()->withTeam()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'team_id' => $user->currentTeam->getKey(),
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->subMinute(),
        ]);

    expect(fn () => resolve(MicrosoftGraphClientFactory::class)->make($account))
        ->toThrow(RuntimeException::class);
});
