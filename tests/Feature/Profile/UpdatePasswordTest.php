<?php

declare(strict_types=1);

use App\Livewire\App\Profile\UpdatePassword as UpdatePasswordComponent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

mutates(UpdatePasswordComponent::class);

test('password component renders correctly', function () {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    Livewire::test(UpdatePasswordComponent::class)
        ->assertSuccessful()
        ->assertSee('Update Password');
});

test('password can be updated', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'currentPassword' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('current password must be correct', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'currentPassword' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertHasFormErrors(['currentPassword']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('new passwords must match', function () {
    $this->actingAs($user = User::factory()->withTeam()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'currentPassword' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'wrong-password',
        ])
        ->call('updatePassword')
        ->assertHasFormErrors(['password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

test('social user sees set password form without current password field', function () {
    $this->actingAs(User::factory()->withTeam()->socialOnly()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->assertSuccessful()
        ->assertSee('Set Password')
        ->assertDontSee('Current Password');
});

test('social user can set a password', function () {
    $this->actingAs($user = User::factory()->withTeam()->socialOnly()->create());

    Livewire::test(UpdatePasswordComponent::class)
        ->fillForm([
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->call('updatePassword')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

test('social user who set a password then sees update password form', function () {
    $user = User::factory()->withTeam()->socialOnly()->create();
    $user->forceFill(['password' => Hash::make('my-password')])->save();

    $this->actingAs($user);

    Livewire::test(UpdatePasswordComponent::class)
        ->assertSuccessful()
        ->assertSee('Update Password')
        ->assertSee('Current Password');
});
