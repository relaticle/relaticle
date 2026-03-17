<?php

declare(strict_types=1);

use App\Filament\Pages\CreateTeam;
use App\Models\Team;
use App\Models\User;

mutates(CreateTeam::class);

it('can create a new team through the browser', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate('/app/new')
        ->assertSee('Create your workspace')
        ->type('[id="form.name"]', 'Second Workspace')
        ->type('[id="form.slug"]', 'second-workspace')
        ->press('Create Team')
        ->assertPathContains('/app/second-workspace/companies');

    expect(Team::where('name', 'Second Workspace')->where('user_id', $user->id)->exists())->toBeTrue();
});
