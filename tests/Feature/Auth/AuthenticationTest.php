<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;

test('login screen can be rendered', function () {
    $response = $this->get(url()->getAppUrl('login'));

    $response->assertStatus(200);
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    livewire(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertRedirect(url()->getAppUrl("{$team->slug}/companies"));

    $this->assertAuthenticated();
});

test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->create();

    livewire(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});
