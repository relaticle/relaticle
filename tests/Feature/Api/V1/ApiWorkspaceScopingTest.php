<?php

declare(strict_types=1);

use App\Http\Middleware\SetApiWorkspaceContext;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;

mutates(SetApiWorkspaceContext::class);

beforeEach(function () {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

it('uses current workspace by default', function (): void {
    Sanctum::actingAs($this->user);

    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $response = $this->getJson('/api/v1/companies');

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($company->id);
});

it('can switch workspace via X-Workspace-Id header', function (): void {
    $otherWorkspace = Workspace::factory()->create();
    $this->user->workspaces()->attach($otherWorkspace);

    $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['workspace_id' => $otherWorkspace->id]));

    Sanctum::actingAs($this->user);

    Company::factory()->recycle([$this->user, $this->workspace])->create();

    $response = $this->getJson('/api/v1/companies', ['X-Workspace-Id' => $otherWorkspace->id]);

    $response->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($otherCompany->id);
});

it('rejects X-Workspace-Id for workspace user does not belong to', function (): void {
    $foreignWorkspace = Workspace::factory()->create();

    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/companies', ['X-Workspace-Id' => $foreignWorkspace->id])
        ->assertForbidden();
});

it('returns 403 when user has no workspace', function (): void {
    $userWithoutWorkspace = User::factory()->create();
    $userWithoutWorkspace->current_workspace_id = null;
    $userWithoutWorkspace->save();

    Sanctum::actingAs($userWithoutWorkspace);

    $this->getJson('/api/v1/companies')
        ->assertForbidden();
});

describe('expired token', function (): void {
    it('returns 401 for an expired token', function (): void {
        $newToken = $this->user->createToken('expired', ['*'], now()->subHour());
        $newToken->accessToken->fill(['workspace_id' => $this->workspace->id])->save();

        $this->withToken($newToken->plainTextToken)
            ->getJson('/api/v1/companies')
            ->assertUnauthorized();
    });
});

describe('token-based workspace scoping', function (): void {
    it('resolves workspace context from token workspace_id', function (): void {
        $otherWorkspace = Workspace::factory()->create();
        $this->user->workspaces()->attach($otherWorkspace);

        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['workspace_id' => $otherWorkspace->id]));
        Company::factory()->recycle([$this->user, $this->workspace])->create();

        $raw = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'workspace-scoped',
            'token' => hash('sha256', $raw),
            'abilities' => ['*'],
            'workspace_id' => $otherWorkspace->id,
        ]);

        $plainToken = "{$token->id}|{$raw}";

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/companies');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($otherCompany->id);
    });

    it('ignores X-Workspace-Id header when token has workspace_id', function (): void {
        $otherWorkspace = Workspace::factory()->create();
        $this->user->workspaces()->attach($otherWorkspace);

        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create(['workspace_id' => $otherWorkspace->id]));
        Company::factory()->recycle([$this->user, $this->workspace])->create();

        $raw = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'workspace-scoped',
            'token' => hash('sha256', $raw),
            'abilities' => ['*'],
            'workspace_id' => $otherWorkspace->id,
        ]);

        $plainToken = "{$token->id}|{$raw}";

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/companies', ['X-Workspace-Id' => $this->workspace->id]);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        expect($ids)->toContain($otherCompany->id);
    });
});

describe('revoked workspace membership', function (): void {
    it('rejects token when user no longer belongs to the token workspace', function (): void {
        $otherWorkspace = Workspace::factory()->create();
        $this->user->workspaces()->attach($otherWorkspace);

        $raw = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'workspace-scoped',
            'token' => hash('sha256', $raw),
            'abilities' => ['*'],
            'workspace_id' => $otherWorkspace->id,
        ]);

        $this->user->workspaces()->detach($otherWorkspace);

        $plainToken = "{$token->id}|{$raw}";

        $this->withToken($plainToken)
            ->getJson('/api/v1/companies')
            ->assertForbidden();
    });
});

describe('switchWorkspace regression', function (): void {
    it('does not persist current_workspace_id to database on API call', function (): void {
        $otherWorkspace = Workspace::factory()->create();
        $this->user->workspaces()->attach($otherWorkspace);

        $this->user->switchWorkspace($this->workspace);
        $originalWorkspaceId = $this->user->fresh()->current_workspace_id;

        $raw = Str::random(40);
        $token = $this->user->tokens()->create([
            'name' => 'workspace-scoped',
            'token' => hash('sha256', $raw),
            'abilities' => ['*'],
            'workspace_id' => $otherWorkspace->id,
        ]);

        $plainToken = "{$token->id}|{$raw}";

        $this->withToken($plainToken)
            ->getJson('/api/v1/companies')
            ->assertOk();

        expect($this->user->fresh()->current_workspace_id)->toBe($originalWorkspaceId);
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
