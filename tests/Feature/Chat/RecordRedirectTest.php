<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Relaticle\Chat\Http\Controllers\RecordRedirectController;

mutates(RecordRedirectController::class);

it('redirects a member to the record view', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $company = Company::factory()->for($team)->create();

    $this->actingAs($user)
        ->get("/r/company/{$company->getKey()}")
        ->assertRedirect(); // Location contains the CompanyResource view URL
});

it('404s for a record outside the current team', function (): void {
    $user = User::factory()->withTeam()->create();
    $other = Company::factory()->create(); // other team

    $this->actingAs($user)->get("/r/company/{$other->getKey()}")->assertNotFound();
});

it('404s for an unknown type', function (): void {
    $user = User::factory()->withTeam()->create();

    $this->actingAs($user)->get('/r/wormhole/123')->assertNotFound();
});

it('requires auth', function (): void {
    $this->get('/r/company/123')->assertRedirect(); // to login
});
