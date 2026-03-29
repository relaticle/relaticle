<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Opportunity\CreateOpportunityTool;
use App\Mcp\Tools\Opportunity\DeleteOpportunityTool;
use App\Mcp\Tools\Opportunity\GetOpportunityTool;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Mcp\Tools\Opportunity\UpdateOpportunityTool;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\TeamScope;
use App\Models\Team;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

afterEach(function () {
    Opportunity::clearBootedModels();
});

it('can get an opportunity by ID', function (): void {
    $opportunity = Opportunity::factory()->for($this->team)->create(['name' => 'Big Deal']);

    RelaticleServer::actingAs($this->user)
        ->tool(GetOpportunityTool::class, ['id' => $opportunity->id])
        ->assertOk()
        ->assertSee('Big Deal');
});

it('can update an opportunity via MCP tool', function (): void {
    $opportunity = Opportunity::factory()->for($this->team)->create(['name' => 'Old Deal']);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateOpportunityTool::class, [
            'id' => $opportunity->id,
            'name' => 'New Deal',
        ])
        ->assertOk()
        ->assertSee('New Deal');

    expect($opportunity->refresh()->name)->toBe('New Deal');
});

it('can delete an opportunity via MCP tool', function (): void {
    $opportunity = Opportunity::factory()->for($this->team)->create(['name' => 'Closing Deal']);

    RelaticleServer::actingAs($this->user)
        ->tool(DeleteOpportunityTool::class, [
            'id' => $opportunity->id,
        ])
        ->assertOk()
        ->assertSee('has been deleted');

    expect($opportunity->refresh()->trashed())->toBeTrue();
});

it('can filter opportunities by contact_id', function (): void {
    $person = People::factory()->for($this->team)->create();
    $matchingOpp = Opportunity::factory()->for($this->team)->create([
        'contact_id' => $person->id,
    ]);
    $otherOpp = Opportunity::factory()->for($this->team)->create();

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'contact_id' => $person->id,
        ])
        ->assertOk()
        ->assertSee($matchingOpp->name)
        ->assertDontSee($otherOpp->name);
});

describe('team scoping', function () {
    beforeEach(function () {
        Opportunity::addGlobalScope(new TeamScope);
    });

    it('scopes opportunities to current team', function (): void {
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'name' => 'Other Team Deal',
        ]));
        $ownOpportunity = Opportunity::factory()->for($this->team)->create(['name' => 'Own Team Deal']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListOpportunitiesTool::class)
            ->assertOk()
            ->assertSee('Own Team Deal')
            ->assertDontSee('Other Team Deal');
    });

    it('cannot update an opportunity from another team', function (): void {
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateOpportunityTool::class, [
                'id' => $otherOpportunity->id,
                'name' => 'Hacked',
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot delete an opportunity from another team', function (): void {
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteOpportunityTool::class, [
                'id' => $otherOpportunity->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot get an opportunity from another team', function (): void {
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(GetOpportunityTool::class, [
                'id' => $otherOpportunity->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('rejects company_id from another team when creating opportunity', function (): void {
        $otherTeam = Team::factory()->create();
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'team_id' => $otherTeam->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(CreateOpportunityTool::class, [
                'name' => 'Test Deal',
                'company_id' => $otherCompany->id,
            ])
            ->assertHasErrors();
    });
});
