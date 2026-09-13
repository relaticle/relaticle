<?php

declare(strict_types=1);

use App\Actions\Mcp\RevokeOAuthConnector;
use App\Livewire\App\AccessTokens\ManageOAuthConnectors;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Livewire\Livewire;

mutates(RevokeOAuthConnector::class, ManageOAuthConnectors::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();

    $this->actingAs($this->user);

    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->workspace);
});

function connectorFor(User $user, Workspace $workspace, string $name = 'Claude'): Client
{
    $client = Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => $name,
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    $accessTokenId = Str::random(80);

    DB::table('oauth_access_tokens')->insert([
        'id' => $accessTokenId,
        'user_id' => $user->getKey(),
        'client_id' => $client->getKey(),
        'workspace_id' => $workspace->getKey(),
        'name' => $name,
        'scopes' => '["mcp:use"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    DB::table('oauth_refresh_tokens')->insert([
        'id' => Str::random(80),
        'access_token_id' => $accessTokenId,
        'revoked' => false,
        'expires_at' => now()->addDays(90),
    ]);

    return $client;
}

it('lists the connectors a user has authorized', function (): void {
    $connector = connectorFor($this->user, $this->workspace);

    Livewire::test(ManageOAuthConnectors::class)
        ->assertCanSeeTableRecords([$connector]);
});

it('does not list another user\'s connectors', function (): void {
    $otherUser = User::factory()->withPersonalWorkspace()->create();
    $theirs = connectorFor($otherUser, $otherUser->personalWorkspace(), 'Someone else ChatGPT');

    Livewire::test(ManageOAuthConnectors::class)
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('revokes both the access token and its refresh token', function (): void {
    $connector = connectorFor($this->user, $this->workspace);

    Livewire::test(ManageOAuthConnectors::class)
        ->callAction(TestAction::make('revoke')->table($connector))
        ->assertNotified();

    expect(DB::table('oauth_access_tokens')->where('client_id', $connector->getKey())->value('revoked'))->toBeTrue();
    expect(DB::table('oauth_refresh_tokens')->value('revoked'))->toBeTrue();
});

it('drops the connector from the list once revoked', function (): void {
    $connector = connectorFor($this->user, $this->workspace);

    Livewire::test(ManageOAuthConnectors::class)
        ->callAction(TestAction::make('revoke')->table($connector))
        ->assertCanNotSeeTableRecords([$connector]);
});

it('leaves other users tokens for the same client untouched', function (): void {
    $connector = connectorFor($this->user, $this->workspace);

    $otherUser = User::factory()->withPersonalWorkspace()->create();
    $otherTokenId = Str::random(80);

    DB::table('oauth_access_tokens')->insert([
        'id' => $otherTokenId,
        'user_id' => $otherUser->getKey(),
        'client_id' => $connector->getKey(),
        'workspace_id' => $otherUser->personalWorkspace()->getKey(),
        'name' => 'Claude',
        'scopes' => '["mcp:use"]',
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
        'expires_at' => now()->addDays(30),
    ]);

    resolve(RevokeOAuthConnector::class)->execute($this->user, (string) $connector->getKey());

    expect(DB::table('oauth_access_tokens')->where('id', $otherTokenId)->value('revoked'))->toBeFalse();
});
