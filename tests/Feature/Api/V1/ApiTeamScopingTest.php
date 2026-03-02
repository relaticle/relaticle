<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('uses current team by default', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->for($this->team)->create();

    $response = $this->getJson('/api/v1/companies');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($company->id);
});

it('can switch team via X-Team-Id header', function (): void {
    $otherTeam = Team::factory()->create();
    $this->user->teams()->attach($otherTeam);

    $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['team_id' => $otherTeam->id]));

    Sanctum::actingAs($this->user);

    Company::factory()->for($this->team)->create();

    $response = $this->getJson('/api/v1/companies', ['X-Team-Id' => $otherTeam->id]);

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($otherCompany->id);
});

it('rejects X-Team-Id for team user does not belong to', function (): void {
    $foreignTeam = Team::factory()->create();

    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/companies', ['X-Team-Id' => $foreignTeam->id])
        ->assertForbidden();
});

it('returns 403 when user has no team', function (): void {
    $userWithoutTeam = User::factory()->create();
    $userWithoutTeam->current_team_id = null;
    $userWithoutTeam->save();

    Sanctum::actingAs($userWithoutTeam);

    $this->getJson('/api/v1/companies')
        ->assertForbidden();
});

describe('/api/user endpoint', function (): void {
    it('returns current authenticated user', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('id', $this->user->id)
                ->where('name', $this->user->name)
                ->where('email', $this->user->email)
                ->missing('password')
                ->etc()
            );
    });

    it('requires authentication', function (): void {
        $this->getJson('/api/user')
            ->assertUnauthorized();
    });
});
