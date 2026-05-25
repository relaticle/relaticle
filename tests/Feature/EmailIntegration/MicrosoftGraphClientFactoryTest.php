<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
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
    $account = ConnectedAccount::query()->create([
        'user_id' => $user->id,
        'team_id' => $user->currentTeam->id,
        'provider' => EmailProvider::AZURE,
        'provider_account_id' => 'graph-1',
        'email_address' => 'a@example.com',
        'access_token' => 'still-valid-token',
        'refresh_token' => 'refresh-1',
        'token_expires_at' => now()->addHour(),
        'status' => EmailAccountStatus::ACTIVE,
        'capabilities' => ['email' => true, 'calendar' => false],
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
    $account = ConnectedAccount::query()->create([
        'user_id' => $user->id,
        'team_id' => $user->currentTeam->id,
        'provider' => EmailProvider::AZURE,
        'provider_account_id' => 'graph-1',
        'email_address' => 'a@example.com',
        'access_token' => 'expired-token',
        'refresh_token' => 'refresh-1',
        'token_expires_at' => now()->subMinute(),
        'status' => EmailAccountStatus::ACTIVE,
        'capabilities' => ['email' => true, 'calendar' => false],
    ]);

    resolve(MicrosoftGraphClientFactory::class)
        ->make($account)
        ->get('https://graph.microsoft.com/v1.0/me');

    expect($account->refresh()->access_token)->toBe('fresh-token')
        ->and($account->refresh_token)->toBe('rotated-refresh')
        ->and($account->token_expires_at->isFuture())->toBeTrue();

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'login.microsoftonline.com'));
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fresh-token'));
});
