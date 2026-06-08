<?php

declare(strict_types=1);

use App\Enums\SocialiteProvider;
use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\RedirectController;
use App\Models\User;
use App\Models\UserSocialAccount;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

mutates(CallbackController::class, RedirectController::class);

function makeSocialiteUser(string $id, string $name, string $email): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $id;
    $user->name = $name;
    $user->email = $email;

    return $user;
}

test('redirect to socialite provider', function () {
    Socialite::fake(SocialiteProvider::GOOGLE->value);

    $response = $this->get(route('auth.socialite.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    $response->assertRedirect();
});

test('callback from socialite provider creates new user when user does not exist', function () {
    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('123456789', 'Test User', 'test@example.com'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    $this->assertDatabaseHas('user_social_accounts', [
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '123456789',
    ]);

    $this->assertAuthenticated();

    $response->assertRedirect(url()->getAppUrl());
});

test('callback from socialite provider logs in existing user when social account exists', function () {
    $user = User::factory()->withTeam()->create([
        'email' => 'existing@example.com',
        'name' => 'Existing User',
    ]);

    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '123456789',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('123456789', 'Existing User', 'existing@example.com'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user);

    $response->assertRedirect(url()->getAppUrl());
});

test('callback from socialite provider links social account to existing user when email matches', function () {
    $user = User::factory()->withTeam()->create([
        'email' => 'existing@example.com',
        'name' => 'Existing User',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('123456789', 'Existing User', 'existing@example.com'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $response->assertRedirect();

    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user);
});

test('callback from socialite provider handles error gracefully', function () {
    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        fn () => throw new Exception('Socialite error'),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
});

test('callback does not report transient provider rate limit to sentry', function () {
    Exceptions::fake();

    Socialite::fake(
        SocialiteProvider::GITHUB->value,
        fn () => throw new ClientException(
            'Client error: `POST https://github.com/login/oauth/access_token` resulted in a `429 Too Many Requests` response',
            new GuzzleRequest('POST', 'https://github.com/login/oauth/access_token'),
            new GuzzleResponse(429),
        ),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GITHUB->value, 'code' => 'test-code']));

    $response->assertRedirect(route('login'));

    $errors = session('errors')->getBag('default');
    expect($errors->first('login'))->toBe('Github is busy right now. Please wait a moment and try again.');

    Exceptions::assertNothingReported();
});

test('callback does not report transient provider server error to sentry', function () {
    Exceptions::fake();

    Socialite::fake(
        SocialiteProvider::GITHUB->value,
        fn () => throw new ServerException(
            'Server error: `POST https://github.com/login/oauth/access_token` resulted in a `503 Service Unavailable` response',
            new GuzzleRequest('POST', 'https://github.com/login/oauth/access_token'),
            new GuzzleResponse(503),
        ),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GITHUB->value, 'code' => 'test-code']));

    $response->assertRedirect(route('login'));
    Exceptions::assertNothingReported();
});

test('callback still reports actionable provider client errors to sentry', function () {
    Exceptions::fake();

    Socialite::fake(
        SocialiteProvider::GITHUB->value,
        fn () => throw new ClientException(
            'Client error: `POST https://github.com/login/oauth/access_token` resulted in a `401 Unauthorized` response',
            new GuzzleRequest('POST', 'https://github.com/login/oauth/access_token'),
            new GuzzleResponse(401),
        ),
    );

    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GITHUB->value, 'code' => 'test-code']));

    $response->assertRedirect(route('login'));
    Exceptions::assertReported(ClientException::class);
});

test('callback from socialite provider handles missing code parameter', function () {
    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
    $response->assertSessionHas('errors');

    $errors = session('errors')->getBag('default');
    expect($errors->first('login'))->toBe('Authorization was cancelled or failed. Please try again.');
});
