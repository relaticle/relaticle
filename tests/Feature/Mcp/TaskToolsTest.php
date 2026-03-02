<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Task\DeleteTaskTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Mcp\Tools\Task\UpdateTaskTool;
use App\Models\Scopes\TeamScope;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

afterEach(function () {
    Task::clearBootedModels();
});

it('can update a task via MCP tool', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'Old Task']);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, [
            'id' => $task->id,
            'title' => 'New Task',
        ])
        ->assertOk()
        ->assertSee('New Task');

    expect($task->refresh()->title)->toBe('New Task');
});

it('can delete a task via MCP tool', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'Delete Me']);

    RelaticleServer::actingAs($this->user)
        ->tool(DeleteTaskTool::class, [
            'id' => $task->id,
        ])
        ->assertOk()
        ->assertSee('has been deleted');

    expect($task->refresh()->trashed())->toBeTrue();
});

describe('team scoping', function () {
    beforeEach(function () {
        Task::addGlobalScope(new TeamScope);
    });

    it('scopes tasks to current team', function (): void {
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'title' => 'Other Team Task',
        ]));
        $ownTask = Task::factory()->for($this->team)->create(['title' => 'Own Team Task']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListTasksTool::class)
            ->assertOk()
            ->assertSee('Own Team Task')
            ->assertDontSee('Other Team Task');
    });

    it('cannot update a task from another team', function (): void {
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateTaskTool::class, [
                'id' => $otherTask->id,
                'title' => 'Hacked',
            ]);
    })->throws(ModelNotFoundException::class);

    it('cannot delete a task from another team', function (): void {
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteTaskTool::class, [
                'id' => $otherTask->id,
            ]);
    })->throws(ModelNotFoundException::class);
});
