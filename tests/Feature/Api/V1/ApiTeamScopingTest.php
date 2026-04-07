<?php

declare(strict_types=1);

use App\Http\Middleware\SetApiTeamContext;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

mutates(SetApiTeamContext::class);

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

describe('expired token', function (): void {
    it('returns 401 for an expired token', function (): void {
        $newToken = $this->user->createToken('expired', ['*'], now()->subHour());
        $newToken->accessToken->fill(['team_id' => $this->team->id])->save();

        $this->withToken($newToken->plainTextToken)
            ->getJson('/api/v1/companies')
            ->assertUnauthorized();
    });
});

describe('token-based team scoping', function (): void {
    it('resolves team context from token team_id', function (): void {
        $otherTeam = Team::factory()->create();
        $this->user->teams()->attach($otherTeam);

        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['team_id' => $otherTeam->id]));
        Company::factory()->for($this->team)->create();

        $raw = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'team-scoped',
            'token' => hash('sha256', $raw),
            'abilities' => ['*'],
            'team_id' => $otherTeam->id,
        ]);

        $plainToken = "{$token->id}|{$raw}";

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/companies');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($otherCompany->id);
    });

    it('ignores X-Team-Id header when token has team_id', function (): void {
        $otherTeam = Team::factory()->create();
        $this->user->teams()->attach($otherTeam);

        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['team_id' => $otherTeam->id]));
        Company::factory()->for($this->team)->create();

        $raw = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'team-scoped',
            'token' => hash('sha256', $raw),
            'abilities' => ['*'],
            'team_id' => $otherTeam->id,
        ]);

        $plainToken = "{$token->id}|{$raw}";

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/companies', ['X-Team-Id' => $this->team->id]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($otherCompany->id);
    });
});

describe('revoked team membership', function (): void {
    it('rejects token when user no longer belongs to the token team', function (): void {
        $otherTeam = Team::factory()->create();
        $this->user->teams()->attach($otherTeam);

        $raw = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'team-scoped',
            'token' => hash('sha256', $raw),
            'abilities' => ['*'],
            'team_id' => $otherTeam->id,
        ]);

        $this->user->teams()->detach($otherTeam);

        $plainToken = "{$token->id}|{$raw}";

        $this->withToken($plainToken)
            ->getJson('/api/v1/companies')
            ->assertForbidden();
    });
});

describe('switchTeam regression', function (): void {
    it('does not persist current_team_id to database on API call', function (): void {
        $otherTeam = Team::factory()->create();
        $this->user->teams()->attach($otherTeam);

        $this->user->switchTeam($this->team);
        $originalTeamId = $this->user->fresh()->current_team_id;

        $raw = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'team-scoped',
            'token' => hash('sha256', $raw),
            'abilities' => ['*'],
            'team_id' => $otherTeam->id,
        ]);

        $plainToken = "{$token->id}|{$raw}";

        $this->withToken($plainToken)
            ->getJson('/api/v1/companies')
            ->assertOk();

        expect($this->user->fresh()->current_team_id)->toBe($originalTeamId);
    });
});

describe('/api/v1/user endpoint', function (): void {
    it('returns current authenticated user', function (): void {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/user')
            ->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('data.id', (string) $this->user->id)
                ->where('data.type', 'users')
                ->where('data.attributes.name', $this->user->name)
                ->where('data.attributes.email', $this->user->email)
                ->missing('data.attributes.password')
                ->etc()
            );
    });

    it('requires authentication', function (): void {
        $this->getJson('/api/v1/user')
            ->assertUnauthorized();
    });
});
