<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\People\DeletePeopleTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Mcp\Tools\People\UpdatePeopleTool;
use App\Models\People;
use App\Models\Scopes\TeamScope;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

afterEach(function () {
    People::clearBootedModels();
});

it('can update a person via MCP tool', function (): void {
    $person = People::factory()->for($this->team)->create(['name' => 'Old Name']);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdatePeopleTool::class, [
            'id' => $person->id,
            'name' => 'New Name',
        ])
        ->assertOk()
        ->assertSee('New Name');

    expect($person->refresh()->name)->toBe('New Name');
});

it('can delete a person via MCP tool', function (): void {
    $person = People::factory()->for($this->team)->create(['name' => 'Jane Doe']);

    RelaticleServer::actingAs($this->user)
        ->tool(DeletePeopleTool::class, [
            'id' => $person->id,
        ])
        ->assertOk()
        ->assertSee('has been deleted');

    expect($person->refresh()->trashed())->toBeTrue();
});

describe('team scoping', function () {
    beforeEach(function () {
        People::addGlobalScope(new TeamScope);
    });

    it('scopes people to current team', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Team::factory())->create([
            'name' => 'Other Team Person',
        ]));
        $ownPerson = People::factory()->for($this->team)->create(['name' => 'Own Team Person']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListPeopleTool::class)
            ->assertOk()
            ->assertSee('Own Team Person')
            ->assertDontSee('Other Team Person');
    });

    it('cannot update a person from another team', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Team::factory())->create());

        RelaticleServer::actingAs($this->user)
            ->tool(UpdatePeopleTool::class, [
                'id' => $otherPerson->id,
                'name' => 'Hacked',
            ]);
    })->throws(ModelNotFoundException::class);

    it('cannot delete a person from another team', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Team::factory())->create());

        RelaticleServer::actingAs($this->user)
            ->tool(DeletePeopleTool::class, [
                'id' => $otherPerson->id,
            ]);
    })->throws(ModelNotFoundException::class);
});
