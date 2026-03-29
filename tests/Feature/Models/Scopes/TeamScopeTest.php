<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Scopes\TeamScope;
use App\Models\User;

mutates(TeamScope::class);

afterEach(function (): void {
    Company::clearBootedModels();
});

it('returns zero results when no user is authenticated', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    Company::withoutEvents(fn () => Company::factory()->create([
        'team_id' => $team->id,
        'account_owner_id' => $user->id,
    ]));

    Company::addGlobalScope(new TeamScope);

    expect(Company::query()->count())->toBe(0);
});

it('scopes results to the authenticated user current team', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->currentTeam;

    $ownCompany = Company::withoutEvents(fn () => Company::factory()->create([
        'team_id' => $team->id,
        'account_owner_id' => $user->id,
    ]));

    $otherUser = User::factory()->withTeam()->create();
    Company::withoutEvents(fn () => Company::factory()->create([
        'team_id' => $otherUser->currentTeam->id,
        'account_owner_id' => $otherUser->id,
    ]));

    $this->actingAs($user);
    Company::addGlobalScope(new TeamScope);

    $results = Company::query()->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($ownCompany->id);
});
