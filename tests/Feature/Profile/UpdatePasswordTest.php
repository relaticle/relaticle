<?php

declare(strict_types=1);

use App\Livewire\App\Profile\UpdatePassword as UpdatePasswordComponent;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

test('password component renders correctly', function () {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    Livewire::test(UpdatePasswordComponent::class)
        ->assertSuccessful()
        ->assertSee('Update Password');
});

test('password can be updated', function () {
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

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
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

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
    $this->actingAs($user = User::factory()->withPersonalTeam()->create());

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
