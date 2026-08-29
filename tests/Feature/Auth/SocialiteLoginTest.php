<?php

declare(strict_types=1);

use App\Enums\SocialiteProvider;
use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\RedirectController;
use App\Models\User;
use App\Models\UserSocialAccount;
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

test('callback from socialite provider handles missing code parameter', function () {
    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
    $response->assertSessionHas('errors');

    $errors = session('errors')->getBag('default');
    expect($errors->first('login'))->toBe('Authorization was cancelled or failed. Please try again.');
});

test('callback from socialite provider rejects a disposable email address', function () {
    Exceptions::fake();

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('987654321', 'Burner User', 'burner@mailinator.com'),
    );

    $response = $this->get(route('auth.socialite.callback', [
        'provider' => SocialiteProvider::GOOGLE->value,
        'code' => 'test-code',
    ]));

    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login' => __('validation.indisposable')]);

    $this->assertDatabaseMissing('users', ['email' => 'burner@mailinator.com']);
    $this->assertGuest();

    Exceptions::assertNothingReported();
});

/**
 * The signup event was flagged only by the registration form, so every OAuth
 * sign-up reached the panel without one. That made the number wrong rather
 * than merely incomplete, and it is the undercount that made the Fathom
 * signup series disagree with the users table.
 */
test('callback flags the signup event when the OAuth user is new', function () {
    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('987654321', 'Fresh User', 'fresh@example.com'),
    );

    $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    expect(session()->get('fathom.track_signup'))->toBeTrue();
});

test('callback does not flag the signup event when the OAuth user already exists', function () {
    $user = User::factory()->withTeam()->create([
        'email' => 'returning@example.com',
        'name' => 'Returning User',
    ]);

    UserSocialAccount::factory()->create([
        'user_id' => $user->getKey(),
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '55555',
    ]);

    Socialite::fake(
        SocialiteProvider::GOOGLE->value,
        makeSocialiteUser('55555', 'Returning User', 'returning@example.com'),
    );

    $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    expect(session()->has('fathom.track_signup'))->toBeFalse();
});
