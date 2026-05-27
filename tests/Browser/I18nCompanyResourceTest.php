<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Models\User;

mutates(CompanyResource::class);

it('renders CompanyResource list page with French labels when locale is fr', function (): void {
    config(['app.locale' => 'fr']);
    app()->setLocale('fr');

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/companies")
        ->assertSee('Entreprises')
        ->assertSee('Espace de travail');
});

it('renders CompanyResource list page with English labels when locale is en', function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/companies")
        ->assertSee('Companies')
        ->assertSee('Workspace');
});
