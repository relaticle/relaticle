<?php

declare(strict_types=1);

use App\Filament\Pages\EditProfile;
use App\Models\User;

beforeEach(function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
});

it('renders the Passkeys section on the profile page', function (): void {
    livewire(EditProfile::class)
        ->assertSee('Passkeys')
        ->assertSee('Manage your passkeys for passwordless sign-in.');
});
