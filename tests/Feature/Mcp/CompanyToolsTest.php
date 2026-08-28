<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseDeleteTool;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\BaseShowTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Mcp\Tools\Company\CreateCompanyTool;
use App\Mcp\Tools\Company\DeleteCompanyTool;
use App\Mcp\Tools\Company\GetCompanyTool;
use App\Mcp\Tools\Company\ListCompaniesTool;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Mcp\Tools\Concerns\SerializesRelatedModels;
use App\Models\Company;
use App\Models\People;
use App\Models\Scopes\TeamScope;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

mutates(
    BaseCreateTool::class,
    BaseDeleteTool::class,
    BaseListTool::class,
    BaseShowTool::class,
    BaseUpdateTool::class,
    CreateCompanyTool::class,
    DeleteCompanyTool::class,
    GetCompanyTool::class,
    ListCompaniesTool::class,
    SerializesRelatedModels::class,
    UpdateCompanyTool::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

afterEach(function (): void {
    Company::clearBootedModels();
});

it('can get a company by ID', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);

    RelaticleServer::actingAs($this->user)
        ->tool(GetCompanyTool::class, ['id' => $company->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('data.id', $company->getKey())
            ->where('data.attributes.name', 'Acme Corp')
            ->missing('relationship_meta')
            ->etc());
});

it('returns bounded related tasks with count and truncation metadata', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $tasks = Task::factory()->count(27)->recycle([$this->user, $this->team])->create();
    $company->tasks()->attach($tasks);

    RelaticleServer::actingAs($this->user)
        ->tool(GetCompanyTool::class, [
            'id' => $company->id,
            'include' => ['tasks'],
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('data.tasks', 25)
            ->where('relationship_meta.tasks.returned', 25)
            ->where('relationship_meta.tasks.total', 27)
            ->where('relationship_meta.tasks.truncated', true)
            ->etc());
});

it('bounds every to-many relationship expanded by a show tool', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    People::factory()->count(27)->recycle([$this->user, $this->team])->create([
        'company_id' => $company->getKey(),
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(GetCompanyTool::class, [
            'id' => $company->getKey(),
            'include' => ['people'],
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('data.people', 25)
            ->where('relationship_meta.people.returned', 25)
            ->where('relationship_meta.people.total', 27)
            ->where('relationship_meta.people.truncated', true)
            ->etc());
});

it('rejects to-many expansion on paginated list tools', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListCompaniesTool::class, ['include' => ['people']])
        ->assertHasErrors(['Use a show tool']);
});

it('returns actionable MCP errors without successful structured content', function (): void {
    $token = $this->user->createToken('mcp-error-contract', ['*']);

    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'update-company-tool',
                'arguments' => [
                    'id' => '01K00000000000000000000000',
                    'name' => 'Unreachable',
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.type', 'text')
        ->assertJsonPath('result.content.0.text', 'company with ID [01K00000000000000000000000] not found.')
        ->assertJsonMissingPath('result.structuredContent');
});

it('can create, update, and clear a company account owner', function (): void {
    $member = User::factory()->create();
    $this->team->users()->attach($member, ['role' => 'editor']);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateCompanyTool::class, [
            'name' => 'Owned Company',
            'account_owner_id' => $member->id,
        ])
        ->assertOk()
        ->assertSee($member->id);

    $company = Company::query()->where('name', 'Owned Company')->firstOrFail();
    expect($company->account_owner_id)->toBe($member->id);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateCompanyTool::class, [
            'id' => $company->id,
            'account_owner_id' => null,
        ])
        ->assertOk();

    expect($company->refresh()->account_owner_id)->toBeNull();
});

it('rejects a company account owner from another team', function (): void {
    $outsider = User::factory()->create();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateCompanyTool::class, [
            'name' => 'Invalid Owner Company',
            'account_owner_id' => $outsider->id,
        ])
        ->assertHasErrors();

    expect(Company::query()->where('name', 'Invalid Owner Company')->exists())->toBeFalse();
});

describe('team scoping', function (): void {
    beforeEach(function (): void {
        // Apply team scope as SetApiTeamContext middleware does in production
        Company::addGlobalScope(new TeamScope);
    });

    it('scopes companies to current team', function (): void {
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'name' => 'Other Team Corp',
        ]));
        $ownCompany = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Own Team Corp']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class)
            ->assertOk()
            ->assertSee('Own Team Corp')
            ->assertDontSee('Other Team Corp');
    });

    it('cannot update a company from another team', function (): void {
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateCompanyTool::class, [
                'id' => $otherCompany->id,
                'name' => 'Hacked',
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot delete a company from another team', function (): void {
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteCompanyTool::class, [
                'id' => $otherCompany->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot get a company from another team', function (): void {
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(GetCompanyTool::class, [
                'id' => $otherCompany->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('excludes soft-deleted companies from list', function (): void {
        $deleted = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Deleted Corp']);
        $deleted->delete();

        $active = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Active Corp']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class)
            ->assertOk()
            ->assertSee('Active Corp')
            ->assertDontSee('Deleted Corp');
    });
});

describe('pagination', function (): void {
    it('can paginate companies via MCP tool', function (): void {
        Company::factory(3)->recycle([$this->user, $this->team])->create();

        $page1 = RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class, [
                'per_page' => 2,
                'page' => 1,
            ]);

        $page1->assertOk();

        $page2 = RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class, [
                'per_page' => 2,
                'page' => 2,
            ]);

        $page2->assertOk();
    });

    it('includes bounded pagination metadata in list responses', function (): void {
        Company::factory(3)->recycle([$this->user, $this->team])->create();

        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class, [
                'per_page' => 2,
                'page' => 1,
            ])
            ->assertOk()
            ->assertSee('"page":1')
            ->assertSee('"per_page":2')
            ->assertSee('"has_more":true')
            ->assertSee('"next_page":2');

        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class, [
                'per_page' => 2,
                'page' => 2,
            ])
            ->assertOk()
            ->assertSee('"has_more":false')
            ->assertSee('"next_page":null');
    });

    it('rejects list page sizes above the MCP payload cap', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class, ['per_page' => 26])
            ->assertHasErrors();
    });
});

describe('custom fields serialization', function (): void {
    it('returns empty custom_fields as object not array', function (): void {
        $company = Company::factory()->recycle([$this->user, $this->team])->create();

        RelaticleServer::actingAs($this->user)
            ->tool(GetCompanyTool::class, ['id' => $company->id])
            ->assertOk()
            ->assertSee('"custom_fields":{}');
    });
});

describe('validation', function (): void {
    it('rejects empty name on create', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, [])
            ->assertHasErrors(['name']);
    });

    it('rejects name exceeding 255 characters', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, [
                'name' => str_repeat('a', 256),
            ])
            ->assertHasErrors(['name']);
    });

    it('reports a non-scalar update id as a validation error', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UpdateCompanyTool::class, [
                'id' => ['not-an-id'],
                'name' => 'Renamed Corp',
            ])
            ->assertHasErrors(['id'])
            ->assertDontSee('internal server error');
    });

    it('sets creation source to MCP', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, [
                'name' => 'Source Test Corp',
            ])
            ->assertOk();

        $company = Company::query()->where('name', 'Source Test Corp')->first();
        expect($company->creation_source)->toBe(CreationSource::MCP);
    });
});

describe('date filtering', function (): void {
    it('filters companies by created_after', function (): void {
        $old = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Ancient Corp']);
        $old->forceFill(['created_at' => now()->subMonth()])->saveQuietly();

        Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Recent Corp']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class, ['created_after' => now()->subWeek()->toDateString()])
            ->assertOk()
            ->assertSee('Recent Corp')
            ->assertDontSee('Ancient Corp');
    });

    it('filters companies by created_before', function (): void {
        $old = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Ancient Corp']);
        $old->forceFill(['created_at' => now()->subMonth()])->saveQuietly();

        Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Recent Corp']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class, ['created_before' => now()->subWeek()->toDateString()])
            ->assertOk()
            ->assertSee('Ancient Corp')
            ->assertDontSee('Recent Corp');
    });
});
