<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Task\AttachTaskToEntitiesTool;
use App\Mcp\Tools\Task\CreateTaskTool;
use App\Mcp\Tools\Task\DeleteTaskTool;
use App\Mcp\Tools\Task\GetTaskTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Mcp\Tools\Task\UpdateTaskTool;
use App\Models\Company;
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

it('can create a task with assignees and company', function (): void {
    $company = Company::factory()->for($this->team)->create();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, [
            'title' => 'Follow Up',
            'company_ids' => [$company->id],
            'assignee_ids' => [$this->user->id],
        ])
        ->assertOk()
        ->assertSee('Follow Up');

    $task = Task::query()->where('title', 'Follow Up')->firstOrFail();
    expect($task->companies)->toHaveCount(1)
        ->and($task->assignees)->toHaveCount(1)
        ->and($task->assignees->first()->id)->toBe($this->user->id);
});

it('can update task assignees', function (): void {
    $task = Task::factory()->for($this->team)->create();
    $member = User::factory()->create();
    $this->team->users()->attach($member);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, [
            'id' => $task->id,
            'assignee_ids' => [$member->id],
        ])
        ->assertOk();

    expect($task->refresh()->assignees)->toHaveCount(1)
        ->and($task->assignees->first()->id)->toBe($member->id);
});

it('can get a task by ID', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'Follow Up Call']);

    RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->id])
        ->assertOk()
        ->assertSee('Follow Up Call');
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

it('can attach assignees to a task', function (): void {
    $task = Task::factory()->for($this->team)->create();

    RelaticleServer::actingAs($this->user)
        ->tool(AttachTaskToEntitiesTool::class, [
            'id' => $task->id,
            'assignee_ids' => [$this->user->id],
        ])
        ->assertOk();

    expect($task->refresh()->assignees)->toHaveCount(1);
});

it('can filter tasks by company_id', function (): void {
    $company = Company::factory()->for($this->team)->create();
    $linkedTask = Task::factory()->for($this->team)->create(['title' => 'Linked Task']);
    $linkedTask->companies()->attach($company);
    $unlinkedTask = Task::factory()->for($this->team)->create(['title' => 'Unlinked Task']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListTasksTool::class, [
            'company_id' => $company->id,
        ])
        ->assertOk()
        ->assertSee('Linked Task')
        ->assertDontSee('Unlinked Task');
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

    it('cannot get a task from another team', function (): void {
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(GetTaskTool::class, [
                'id' => $otherTask->id,
            ]);
    })->throws(ModelNotFoundException::class);
});
