<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

function changePassword(User $user): void
{
    $user->forceFill(['password' => Hash::make('a-new-password')])->save();
}

function oauthClient(User $user): Client
{
    return Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => 'Claude',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);
}

function authorizeUrlFor(Client $client): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://example.com/callback',
        'response_type' => 'code',
        'scope' => '',
        'state' => 'test-state',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]);
}

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
});

it('keeps a chat session alive while the password is unchanged', function (): void {
    actingAs($this->user);

    getJson(route('chat.conversations'))->assertOk();
    getJson(route('chat.conversations'))->assertOk();
});

it('rejects a chat session opened before the password changed', function (): void {
    actingAs($this->user);
    getJson(route('chat.conversations'))->assertOk();

    changePassword($this->user);

    getJson(route('chat.conversations'))->assertUnauthorized();
});

it('rejects a broadcasting auth request from a session opened before the password changed', function (): void {
    actingAs($this->user);
    getJson(route('chat.conversations'))->assertOk();

    changePassword($this->user);

    postJson('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-App.Models.User.{$this->user->getKey()}",
    ])->assertUnauthorized();
});

it('rejects an account settings write from a session opened before the password changed', function (): void {
    actingAs($this->user);
    getJson(route('chat.conversations'))->assertOk();

    changePassword($this->user);

    putJson(route('user-profile-information.update'), ['name' => 'Someone Else', 'email' => $this->user->email])
        ->assertUnauthorized();

    expect($this->user->fresh()->name)->not->toBe('Someone Else');
});

it('refuses a transient api token to a session opened before the password changed', function (): void {
    actingAs($this->user);
    getJson(route('chat.conversations'))->assertOk();

    changePassword($this->user);

    postJson(route('passport.token.refresh'))->assertUnauthorized();
});

it('keeps the session that changed the password through the account endpoint signed in', function (): void {
    actingAs($this->user);
    getJson(route('chat.conversations'))->assertOk();

    AuthenticationSession::startOperation($this->user, 'set_password', null);
    AuthenticationSession::proveOperation($this->user, 'set_password', null);

    putJson(route('user-password.update'), [
        'password' => 'a-new-password',
        'password_confirmation' => 'a-new-password',
    ])->assertOk();

    getJson(route('chat.conversations'))->assertOk();
});

it('rejects an oauth consent approval from a session opened before the password changed', function (): void {
    $client = oauthClient($this->user);

    actingAs($this->user);
    getJson(route('chat.conversations'))->assertOk();

    changePassword($this->user);

    postJson('/oauth/authorize', ['auth_token' => 'x', 'client_id' => $client->getKey()])->assertUnauthorized();
});

it('rejects the oauth authorize screen for a session opened before the password changed', function (): void {
    $client = oauthClient($this->user);

    actingAs($this->user);
    getJson(route('chat.conversations'))->assertOk();

    changePassword($this->user);

    $this->get(authorizeUrlFor($client))->assertRedirect();
    $this->assertGuest();
});

it('still serves the oauth authorize screen to a current session', function (): void {
    $client = oauthClient($this->user);

    actingAs($this->user);
    getJson(route('chat.conversations'))->assertOk();

    $this->get(authorizeUrlFor($client))->assertSuccessful();
    $this->assertAuthenticatedAs($this->user);
});
