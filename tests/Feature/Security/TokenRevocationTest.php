<?php

declare(strict_types=1);

use App\Actions\Auth\RevokeUserTokens;
use App\Filament\Pages\Auth\ResetPassword;
use App\Livewire\App\Profile\LogoutOtherBrowserSessions;
use App\Livewire\App\Profile\UpdatePassword;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Laravel\Passport\Client;

mutates(RevokeUserTokens::class, UpdatePassword::class, LogoutOtherBrowserSessions::class, ResetPassword::class);

function issueTokensFor(User $user): void
{
    $user->createToken('cli');

    $client = Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => 'Claude',
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
        'name' => 'Claude',
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

    DB::table('oauth_auth_codes')->insert([
        'id' => Str::random(80),
        'user_id' => $user->getKey(),
        'client_id' => $client->getKey(),
        'scopes' => '["mcp:use"]',
        'revoked' => false,
        'expires_at' => now()->addMinutes(10),
    ]);

    DB::table('oauth_device_codes')->insert([
        'id' => Str::random(80),
        'user_id' => $user->getKey(),
        'client_id' => $client->getKey(),
        'user_code' => Str::upper(Str::random(8)),
        'scopes' => '["mcp:use"]',
        'revoked' => false,
        'expires_at' => now()->addMinutes(10),
    ]);
}

function expectNoUsableTokens(User $user): void
{
    expect($user->tokens()->count())->toBe(0)
        ->and(DB::table('oauth_access_tokens')->where('user_id', $user->getKey())->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_auth_codes')->where('user_id', $user->getKey())->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_device_codes')->where('user_id', $user->getKey())->where('revoked', false)->count())->toBe(0);
}

test('the action removes sanctum tokens and revokes passport tokens', function (): void {
    $user = User::factory()->withWorkspace()->create();
    issueTokensFor($user);

    resolve(RevokeUserTokens::class)->execute($user);

    expectNoUsableTokens($user);
    expect(DB::table('oauth_access_tokens')->where('user_id', $user->getKey())->count())->toBe(1);
});

test('the action leaves another user\'s tokens alone', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $other = User::factory()->withWorkspace()->create();
    issueTokensFor($user);
    issueTokensFor($other);

    resolve(RevokeUserTokens::class)->execute($user);

    expect($other->tokens()->count())->toBe(1)
        ->and(DB::table('oauth_access_tokens')->where('user_id', $other->getKey())->where('revoked', false)->count())->toBe(1);
});

test('changing the password revokes tokens and keeps the current session', function (): void {
    $this->actingAs($user = User::factory()->withWorkspace()->create());
    issueTokensFor($user);

    Livewire::test(UpdatePassword::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->setActionData(['password' => 'password'])
        ->callMountedAction()
        ->assertNotified();

    expectNoUsableTokens($user);
    $this->assertAuthenticatedAs($user);
});

test('resetting the password through the panel revokes tokens', function (): void {
    $user = User::factory()->withWorkspace()->create();
    issueTokensFor($user);
    $token = Password::broker('users')->createToken($user);

    livewire(ResetPassword::class, ['email' => $user->email, 'token' => $token])
        ->fillForm([
            'password' => 'new-secure-password',
            'passwordConfirmation' => 'new-secure-password',
        ])
        ->call('resetPassword')
        ->assertRedirect();

    expectNoUsableTokens($user);
});

test('a failed password reset keeps tokens', function (): void {
    $user = User::factory()->withWorkspace()->create();
    issueTokensFor($user);

    livewire(ResetPassword::class, ['email' => $user->email, 'token' => 'invalid-token'])
        ->fillForm([
            'password' => 'new-secure-password',
            'passwordConfirmation' => 'new-secure-password',
        ])
        ->call('resetPassword');

    expect($user->tokens()->count())->toBe(1);
});

test('logging out other browsers revokes tokens and keeps the current session', function (): void {
    config(['session.driver' => 'array']);

    $this->actingAs($user = User::factory()->withWorkspace()->create());
    session()->put('auth.password_confirmed_at', time());
    issueTokensFor($user);

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions')
        ->assertSuccessful();

    expectNoUsableTokens($user);
    $this->assertAuthenticatedAs($user);
});
