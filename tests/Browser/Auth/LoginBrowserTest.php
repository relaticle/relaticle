<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;

mutates(Login::class);

it('user can log in and reach the dashboard', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}");
});

it('reveals the password field only after continue', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->visit('/app/login')
        ->assertMissing('[id="form.password"]')
        ->type('[id="form.email"]', $user->email)
        ->click('button[type="submit"]')
        ->assertVisible('[id="form.password"]');
});
