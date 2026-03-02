<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Company\CreateCompanyTool;
use App\Mcp\Tools\Company\DeleteCompanyTool;
use App\Mcp\Tools\Company\ListCompaniesTool;
use App\Mcp\Tools\Company\UpdateCompanyTool;
use App\Models\Company;
use App\Models\Scopes\TeamScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

afterEach(function () {
    Company::clearBootedModels();
});

describe('team scoping', function () {
    beforeEach(function () {
        // Apply team scope as SetApiTeamContext middleware does in production
        Company::addGlobalScope(new TeamScope);
    });

    it('scopes companies to current team', function (): void {
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'name' => 'Other Team Corp',
        ]));
        $ownCompany = Company::factory()->for($this->team)->create(['name' => 'Own Team Corp']);

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
            ]);
    })->throws(ModelNotFoundException::class);

    it('cannot delete a company from another team', function (): void {
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteCompanyTool::class, [
                'id' => $otherCompany->id,
            ]);
    })->throws(ModelNotFoundException::class);

    it('excludes soft-deleted companies from list', function (): void {
        $deleted = Company::factory()->for($this->team)->create(['name' => 'Deleted Corp']);
        $deleted->delete();

        $active = Company::factory()->for($this->team)->create(['name' => 'Active Corp']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListCompaniesTool::class)
            ->assertOk()
            ->assertSee('Active Corp')
            ->assertDontSee('Deleted Corp');
    });
});

describe('pagination', function () {
    it('can paginate companies via MCP tool', function (): void {
        Company::factory(3)->for($this->team)->create();

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
});

describe('validation', function () {
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

    it('sets creation source to MCP', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(CreateCompanyTool::class, [
                'name' => 'Source Test Corp',
            ])
            ->assertOk();

        $company = Company::query()->where('name', 'Source Test Corp')->first();
        expect($company->creation_source)->toBe(\App\Enums\CreationSource::MCP);
    });
});
