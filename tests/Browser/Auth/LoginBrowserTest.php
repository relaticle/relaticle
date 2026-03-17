<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Login;
use App\Models\User;

mutates(Login::class);

it('user can log in and reach the dashboard', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies");
});
