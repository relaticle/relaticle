<?php

declare(strict_types=1);

use App\Enums\SocialiteProvider;
use App\Models\User;
use App\Models\UserSocialAccount;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

test('redirect to socialite provider', function () {
    $response = $this->get(route('auth.socialite.redirect', ['provider' => SocialiteProvider::GOOGLE->value]));

    $response->assertRedirect();
});

test('callback from socialite provider creates new user when user does not exist', function () {
    // Mock the Socialite facade
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('123456789');
    $socialiteUser->shouldReceive('getName')->andReturn('Test User');
    $socialiteUser->shouldReceive('getEmail')->andReturn('test@example.com');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->with(SocialiteProvider::GOOGLE->value)
        ->andReturn($provider);

    // Make the request to the callback route with required code parameter
    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    // Assert that a new user was created
    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'name' => 'Test User',
    ]);

    // Assert that a social account was created
    $this->assertDatabaseHas('user_social_accounts', [
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '123456789',
    ]);

    // Assert that the user is authenticated
    $this->assertAuthenticated();

    // Assert that the user is redirected to the dashboard
    $response->assertRedirect(url()->getAppUrl());
});

test('callback from socialite provider logs in existing user when social account exists', function () {
    // Create a user and social account
    $user = User::factory()->withTeam()->create([
        'email' => 'existing@example.com',
        'name' => 'Existing User',
    ]);

    UserSocialAccount::factory()->create([
        'user_id' => $user->id,
        'provider_name' => SocialiteProvider::GOOGLE->value,
        'provider_id' => '123456789',
    ]);

    // Mock the Socialite facade
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('123456789');
    $socialiteUser->shouldReceive('getName')->andReturn('Existing User');
    $socialiteUser->shouldReceive('getEmail')->andReturn('existing@example.com');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->with(SocialiteProvider::GOOGLE->value)
        ->andReturn($provider);

    // Make the request to the callback route with required code parameter
    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    // Assert that the user is authenticated
    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user);

    // Assert that the user is redirected to the app URL
    $response->assertRedirect(url()->getAppUrl());
});

test('callback from socialite provider links social account to existing user when email matches', function () {
    // Create a user without a social account
    $user = User::factory()->withTeam()->create([
        'email' => 'existing@example.com',
        'name' => 'Existing User',
    ]);

    // Mock the Socialite facade
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->shouldReceive('getId')->andReturn('123456789');
    $socialiteUser->shouldReceive('getName')->andReturn('Existing User');
    $socialiteUser->shouldReceive('getEmail')->andReturn('existing@example.com');

    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andReturn($socialiteUser);

    Socialite::shouldReceive('driver')
        ->with(SocialiteProvider::GOOGLE->value)
        ->andReturn($provider);

    // Make the request to the callback route with required code parameter
    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    // Check if the response is a redirect to the dashboard
    $response->assertRedirect();

    // Assert that the user is authenticated
    $this->assertAuthenticated();
    $this->assertAuthenticatedAs($user);
});

test('callback from socialite provider handles error gracefully', function () {
    // Mock the Socialite facade to throw an exception
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->shouldReceive('user')->andThrow(new \Exception('Socialite error'));

    Socialite::shouldReceive('driver')
        ->with(SocialiteProvider::GOOGLE->value)
        ->andReturn($provider);

    // Make the request to the callback route with required code parameter
    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value, 'code' => 'test-code']));

    // Assert that the user is redirected to the login page with an error
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
});

test('callback from socialite provider handles missing code parameter', function () {
    // Make the request to the callback route without a code parameter
    $response = $this->get(route('auth.socialite.callback', ['provider' => SocialiteProvider::GOOGLE->value]));

    // Assert that the user is redirected to the login page with an error
    $response->assertRedirect(route('login'));
    $response->assertSessionHasErrors(['login']);
    $response->assertSessionHas('errors');

    $errors = session('errors')->getBag('default');
    expect($errors->first('login'))->toBe('Authorization was cancelled or failed. Please try again.');
});
