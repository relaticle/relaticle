<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Listeners\SeedTeamCreditBalanceListener;
use App\Models\Team;
use App\Models\User;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(SeedTeamCreditBalanceListener::class);

it('seeds a free-plan balance when a Team is created via the normal flow', function (): void {
    $user = User::factory()->create();

    $team = Team::forceCreate([
        'user_id' => $user->getKey(),
        'name' => 'New Team',
        'personal_team' => false,
        'slug' => 'new-team-'.now()->timestamp,
        'plan' => Plan::default()->value,
    ]);

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->exists())->toBeTrue();
});

it('seeds a balance for the personal team created during user signup', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->ownedTeams()->first();

    expect($team)->not->toBeNull()
        ->and(AiCreditBalance::query()->where('team_id', $team->getKey())->exists())->toBeTrue();
});
