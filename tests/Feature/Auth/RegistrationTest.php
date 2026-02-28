<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Models\User;

it('registers a new user without creating a team', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-test@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'jane-test@gmail.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->ownedTeams)->toHaveCount(0)
        ->and($user->personalTeam())->toBeNull();
});
