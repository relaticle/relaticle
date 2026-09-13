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
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

afterEach(function () {
    Opportunity::clearBootedModels();
});

it('can get an opportunity by ID', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Big Deal']);

    RelaticleServer::actingAs($this->user)
        ->tool(GetOpportunityTool::class, ['id' => $opportunity->id])
        ->assertOk()
        ->assertSee('Big Deal');
});

it('can update an opportunity via MCP tool', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Old Deal']);

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
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Closing Deal']);

    RelaticleServer::actingAs($this->user)
        ->tool(DeleteOpportunityTool::class, [
            'id' => $opportunity->id,
        ])
        ->assertOk()
        ->assertSee('has been deleted');

    expect($opportunity->refresh()->trashed())->toBeTrue();
});

it('can filter opportunities by contact_id', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    $matchingOpp = Opportunity::factory()->recycle([$this->user, $this->workspace])->create([
        'contact_id' => $person->id,
    ]);
    $otherOpp = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'contact_id' => $person->id,
        ])
        ->assertOk()
        ->assertSee($matchingOpp->name)
        ->assertDontSee($otherOpp->name);
});

describe('workspace scoping', function () {
    beforeEach(function () {
        Opportunity::addGlobalScope(new WorkspaceScope);
    });

    it('scopes opportunities to current workspace', function (): void {
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
            'name' => 'Other Workspace Deal',
        ]));
        $ownOpportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Own Workspace Deal']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListOpportunitiesTool::class)
            ->assertOk()
            ->assertSee('Own Workspace Deal')
            ->assertDontSee('Other Workspace Deal');
    });

    it('cannot update an opportunity from another workspace', function (): void {
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateOpportunityTool::class, [
                'id' => $otherOpportunity->id,
                'name' => 'Hacked',
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot delete an opportunity from another workspace', function (): void {
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteOpportunityTool::class, [
                'id' => $otherOpportunity->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot get an opportunity from another workspace', function (): void {
        $otherOpportunity = Opportunity::withoutEvents(fn () => Opportunity::factory()->create([
            'workspace_id' => Workspace::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(GetOpportunityTool::class, [
                'id' => $otherOpportunity->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('rejects company_id from another workspace when creating opportunity', function (): void {
        $otherWorkspace = Workspace::factory()->create();
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'workspace_id' => $otherWorkspace->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(CreateOpportunityTool::class, [
                'name' => 'Test Deal',
                'company_id' => $otherCompany->id,
            ])
            ->assertHasErrors();
    });
});

describe('stale filtering', function () {
    it('filters opportunities by stale_days', function (): void {
        $this->travelTo(now()->subDays(40));
        Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Stale Deal']);

        $this->travelBack();
        Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Active Deal']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListOpportunitiesTool::class, ['stale_days' => 30])
            ->assertOk()
            ->assertSee('Stale Deal')
            ->assertDontSee('Active Deal');
    });
});
