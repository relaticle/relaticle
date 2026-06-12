<?php

declare(strict_types=1);

use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\People\CreatePeople;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Team;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('rejects updating opportunity with company_id from another team', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $teamA = $userA->currentTeam;
    $teamB = Team::factory()->create();

    $opp = Opportunity::factory()->for($teamA)->create();
    $foreignCompany = Company::factory()->for($teamB)->create();

    $this->actingAs($userA);

    expect(
        fn () => app(UpdateOpportunity::class)->execute($userA, $opp, [
            'company_id' => $foreignCompany->id,
        ])
    )->toThrow(ValidationException::class);

    expect($opp->refresh()->company_id)->not->toBe($foreignCompany->id);
});

it('rejects creating person with company_id from another team', function (): void {
    $userA = User::factory()->withPersonalTeam()->create();
    $teamB = Team::factory()->create();

    $foreignCompany = Company::factory()->for($teamB)->create();

    $this->actingAs($userA);

    expect(
        fn () => app(CreatePeople::class)->execute($userA, [
            'name' => 'Test',
            'company_id' => $foreignCompany->id,
        ])
    )->toThrow(ValidationException::class);
});
