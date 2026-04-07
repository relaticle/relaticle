<?php

declare(strict_types=1);

use App\Livewire\App\Profile\LogoutOtherBrowserSessions;
use App\Models\User;
use Livewire\Livewire;

mutates(LogoutOtherBrowserSessions::class);

test('social user can log out other sessions without password', function () {
    $this->actingAs(User::factory()->withTeam()->socialOnly()->create());

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions', null)
        ->assertSuccessful();
});

test('password user can log out other sessions with correct password', function () {
    $this->actingAs(User::factory()->withTeam()->create());

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions', 'password')
        ->assertSuccessful();
});

test('password user cannot log out other sessions with wrong password', function () {
    $this->actingAs(User::factory()->withTeam()->create());

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions', 'wrong-password')
        ->assertHasErrors(['password']);
});

test('password user cannot log out other sessions without password', function () {
    $this->actingAs(User::factory()->withTeam()->create());

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->call('logoutOtherBrowserSessions', null)
        ->assertHasErrors(['password']);
});

test('browser sessions component renders correctly', function () {
    $this->actingAs(User::factory()->withTeam()->create());

    Livewire::test(LogoutOtherBrowserSessions::class)
        ->assertSuccessful()
        ->assertSee('Browser Sessions');
});
