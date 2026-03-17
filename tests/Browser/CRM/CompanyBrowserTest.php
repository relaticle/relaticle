<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;

mutates(CompanyResource::class);

it('can create a company through the browser', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->press('New company')
        ->type('[id="mountedActionSchema0.name"]', 'Browser Test Corp')
        ->press('Create')
        ->assertSee('Browser Test Corp');

    expect(Company::where('name', 'Browser Test Corp')->where('team_id', $team->id)->exists())->toBeTrue();
});
