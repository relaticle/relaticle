<?php

declare(strict_types=1);

use App\Actions\Task\AttachTaskRelationships;
use App\Actions\Task\CreateTask;
use App\Actions\Task\DetachTaskRelationships;
use App\Actions\Task\NotifyTaskAssignees;
use App\Actions\Task\UpdateTask;
use App\Mail\TaskAssignedMail;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseAttachTool;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseDeleteTool;
use App\Mcp\Tools\BaseDetachTool;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\BaseShowTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Mcp\Tools\Concerns\SerializesRelatedModels;
use App\Mcp\Tools\Task\AttachTaskToEntitiesTool;
use App\Mcp\Tools\Task\CreateTaskTool;
use App\Mcp\Tools\Task\DeleteTaskTool;
use App\Mcp\Tools\Task\DetachTaskFromEntitiesTool;
use App\Mcp\Tools\Task\GetTaskTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Mcp\Tools\Task\UpdateTaskTool;
use App\Models\Company;
use App\Models\Scopes\TeamScope;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\Fluent\AssertableJson;

mutates(
    AttachTaskRelationships::class,
    AttachTaskToEntitiesTool::class,
    BaseAttachTool::class,
    BaseCreateTool::class,
    BaseDeleteTool::class,
    BaseDetachTool::class,
    BaseListTool::class,
    BaseShowTool::class,
    BaseUpdateTool::class,
    CreateTask::class,
    CreateTaskTool::class,
    DeleteTaskTool::class,
    DetachTaskRelationships::class,
    DetachTaskFromEntitiesTool::class,
    GetTaskTool::class,
    ListTasksTool::class,
    NotifyTaskAssignees::class,
    SerializesRelatedModels::class,
    UpdateTask::class,
    UpdateTaskTool::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

afterEach(function (): void {
    Task::clearBootedModels();
});

it('can create a task with assignees and company', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();

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

it('reports per-item validation errors with correct array index via MCP', function (): void {
    $validCompany = Company::factory()->recycle([$this->user, $this->team])->create();
    $otherTeam = Team::factory()->create();
    $invalidCompany = Company::factory()->for($otherTeam)->create();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, [
            'title' => 'Mixed',
            'company_ids' => [$validCompany->id, $invalidCompany->id],
        ])
        ->assertHasErrors(['company_ids.1']);
});

it('validates large arrays in bounded queries via MCP', function (): void {
    $companies = Company::factory()->count(10)->recycle([$this->user, $this->team])->create();

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, [
            'title' => 'Large',
            'company_ids' => $companies->pluck('id')->all(),
        ])
        ->assertOk();

    $lookups = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'from "companies"') && str_contains($q['query'], 'team_id'))
        ->count();

    expect($lookups)->toBeLessThanOrEqual(2);
});

it('can update task assignees', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->team])->create();
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
    $task = Task::factory()->recycle([$this->user, $this->team])->create(['title' => 'Follow Up Call']);

    RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->id])
        ->assertOk()
        ->assertSee('Follow Up Call');
});

it('can update a task via MCP tool', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->team])->create(['title' => 'Old Task']);

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
    $task = Task::factory()->recycle([$this->user, $this->team])->create(['title' => 'Delete Me']);

    RelaticleServer::actingAs($this->user)
        ->tool(DeleteTaskTool::class, [
            'id' => $task->id,
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('deleted', true)
            ->where('type', 'task')
            ->where('id', $task->getKey())
            ->where('name', 'Delete Me')
            ->where('message', "Task 'Delete Me' has been deleted."));

    expect($task->refresh()->trashed())->toBeTrue();
});

it('can attach assignees to a task', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->team])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(AttachTaskToEntitiesTool::class, [
            'id' => $task->id,
            'assignee_ids' => [$this->user->id],
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('data.id', $task->getKey())
            ->where('relationship_counts.assignees', 1)
            ->etc());

    expect($task->refresh()->assignees)->toHaveCount(1);
});

it('notifies assignees added through the MCP attach tool', function (): void {
    Mail::fake();

    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $member = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $this->team->users()->attach($member, ['role' => 'editor']);

    RelaticleServer::actingAs($this->user)
        ->tool(AttachTaskToEntitiesTool::class, [
            'id' => $task->id,
            'assignee_ids' => [$member->id],
        ])
        ->assertOk();

    defer()->invoke();

    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($member->email));
});

it('only notifies assignees attached by its own write', function (): void {
    Mail::fake();

    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $intendedAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $concurrentAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $this->team->users()->attach([$intendedAssignee->id, $concurrentAssignee->id], ['role' => 'editor']);

    $concurrentAssignmentAdded = false;
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($task, $concurrentAssignee, &$concurrentAssignmentAdded): void {
        if ($concurrentAssignmentAdded || $event->connection->transactionLevel() !== 1) {
            return;
        }

        $concurrentAssignmentAdded = true;
        DB::table('task_user')->insert([
            'task_id' => $task->id,
            'user_id' => $concurrentAssignee->id,
        ]);
    });

    RelaticleServer::actingAs($this->user)
        ->tool(AttachTaskToEntitiesTool::class, [
            'id' => $task->id,
            'assignee_ids' => [$intendedAssignee->id],
        ])
        ->assertOk();

    defer()->invoke();

    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($intendedAssignee->email));
    Mail::assertNotQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($concurrentAssignee->email));
});

it('only notifies assignees added by its own update', function (): void {
    Mail::fake();

    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $intendedAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $concurrentAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $this->team->users()->attach([$intendedAssignee->id, $concurrentAssignee->id], ['role' => 'editor']);

    $concurrentAssignmentAdded = false;
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($task, $concurrentAssignee, &$concurrentAssignmentAdded): void {
        if ($concurrentAssignmentAdded || $event->connection->transactionLevel() !== 1) {
            return;
        }

        $concurrentAssignmentAdded = true;
        DB::table('task_user')->insert([
            'task_id' => $task->id,
            'user_id' => $concurrentAssignee->id,
        ]);
    });

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, [
            'id' => $task->id,
            'assignee_ids' => [$intendedAssignee->id],
        ])
        ->assertOk();

    defer()->invoke();

    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($intendedAssignee->email));
    Mail::assertNotQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($concurrentAssignee->email));
});

it('does not re-notify an assignee the update kept in place', function (): void {
    Mail::fake();

    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $existingAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $newAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $this->team->users()->attach([$existingAssignee->id, $newAssignee->id], ['role' => 'editor']);
    $task->assignees()->attach($existingAssignee);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, [
            'id' => $task->id,
            'assignee_ids' => [$existingAssignee->id, $newAssignee->id],
        ])
        ->assertOk();

    defer()->invoke();

    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($newAssignee->email));
    Mail::assertNotQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($existingAssignee->email));
});

it('only notifies assignees added by its own create', function (): void {
    Mail::fake();

    $intendedAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $concurrentAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $this->team->users()->attach([$intendedAssignee->id, $concurrentAssignee->id], ['role' => 'editor']);

    $concurrentAssignmentAdded = false;
    Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use ($concurrentAssignee, &$concurrentAssignmentAdded): void {
        if ($concurrentAssignmentAdded || $event->connection->transactionLevel() !== 1) {
            return;
        }

        $taskId = DB::table('tasks')->where('title', 'Concurrent create notification')->value('id');

        if (! is_string($taskId)) {
            return;
        }

        $concurrentAssignmentAdded = true;
        DB::table('task_user')->insert([
            'task_id' => $taskId,
            'user_id' => $concurrentAssignee->id,
        ]);
    });

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, [
            'title' => 'Concurrent create notification',
            'assignee_ids' => [$intendedAssignee->id],
        ])
        ->assertOk();

    defer()->invoke();

    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($intendedAssignee->email));
    Mail::assertNotQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($concurrentAssignee->email));
});

it('does not duplicate assignees or notifications when an attachment is repeated', function (): void {
    Mail::fake();

    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $member = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $this->team->users()->attach($member, ['role' => 'editor']);

    foreach (range(1, 2) as $attempt) {
        RelaticleServer::actingAs($this->user)
            ->tool(AttachTaskToEntitiesTool::class, [
                'id' => $task->id,
                'assignee_ids' => [$member->id],
            ])
            ->assertOk();

        defer()->invoke();
    }

    expect(DB::table('task_user')
        ->where('task_id', $task->id)
        ->where('user_id', $member->id)
        ->count())->toBe(1);
    Mail::assertQueued(TaskAssignedMail::class, 1);
});

it('waits for a concurrent task row lock before attaching relationships', function (): void {
    Mail::fake();

    $this->user->update([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $task = Task::factory()->recycle([$this->user, $this->team])->create();

    $defaultConnectionName = DB::getDefaultConnection();
    /** @var array<string, mixed> $connectionConfig */
    $connectionConfig = config("database.connections.{$defaultConnectionName}");
    DB::connection($defaultConnectionName)->commit();

    config()->set('database.connections.relationship_lock_holder', $connectionConfig);
    config()->set('database.connections.relationship_writer', $connectionConfig);

    $lockHolder = DB::connection('relationship_lock_holder');
    $writer = DB::connection('relationship_writer');

    try {
        $lockHolder->beginTransaction();
        $lockedTask = $lockHolder->table('tasks')->where('id', $task->id)->lockForUpdate()->first();
        expect($lockedTask)->not->toBeNull();

        $writer->statement("SET lock_timeout TO '250ms'");
        DB::setDefaultConnection('relationship_writer');
        $writerUser = User::query()->findOrFail($this->user->id);

        RelaticleServer::actingAs($writerUser)
            ->tool(AttachTaskToEntitiesTool::class, [
                'id' => $task->id,
                'assignee_ids' => [$writerUser->id],
            ])
            ->assertHasErrors();
        Exceptions::assertReported(
            fn (QueryException $exception): bool => str_contains($exception->getMessage(), 'lock timeout'),
        );

        $lockHolder->rollBack();
        $writer->statement('SET lock_timeout TO DEFAULT');

        RelaticleServer::actingAs($writerUser)
            ->tool(AttachTaskToEntitiesTool::class, [
                'id' => $task->id,
                'assignee_ids' => [$writerUser->id],
            ])
            ->assertOk();

        defer()->invoke();

        expect($writer->table('task_user')
            ->where('task_id', $task->id)
            ->where('user_id', $writerUser->id)
            ->count())->toBe(1);
        Mail::assertQueued(TaskAssignedMail::class, 1);
    } finally {
        if ($lockHolder->transactionLevel() > 0) {
            $lockHolder->rollBack();
        }

        DB::setDefaultConnection($defaultConnectionName);
        DB::connection($defaultConnectionName)
            ->table('notifications')
            ->where('notifiable_id', $this->user->id)
            ->delete();
        DB::connection($defaultConnectionName)
            ->table('users')
            ->where('id', $this->user->id)
            ->delete();
        DB::purge('relationship_lock_holder');
        DB::purge('relationship_writer');
    }
});

it('can detach a former team member from a task', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $formerMember = User::factory()->create();
    $this->team->users()->attach($formerMember, ['role' => 'editor']);
    $task->assignees()->attach($formerMember);
    $this->team->users()->detach($formerMember);

    RelaticleServer::actingAs($this->user)
        ->tool(DetachTaskFromEntitiesTool::class, [
            'id' => $task->id,
            'assignee_ids' => [$formerMember->id],
        ])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('data.id', $task->getKey())
            ->where('relationship_counts.assignees', 0)
            ->etc());

    expect(DB::table('task_user')
        ->where('task_id', $task->id)
        ->where('user_id', $formerMember->id)
        ->exists())->toBeFalse();
});

it('cannot attach relationships to a task outside the current team', function (): void {
    $otherTeam = Team::factory()->for($this->user, 'owner')->create();
    $this->user->unsetRelation('ownedTeams');
    $otherTask = Task::withoutEvents(fn () => Task::factory()->for($otherTeam)->create());
    $company = Company::factory()->recycle([$this->user, $this->team])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(AttachTaskToEntitiesTool::class, [
            'id' => $otherTask->id,
            'company_ids' => [$company->id],
        ])
        ->assertHasErrors();

    expect($otherTask->companies()->whereKey($company->id)->exists())->toBeFalse();
});

it('cannot detach relationships from a task outside the current team', function (): void {
    $otherTeam = Team::factory()->for($this->user, 'owner')->create();
    $this->user->unsetRelation('ownedTeams');
    $otherTask = Task::withoutEvents(fn () => Task::factory()->for($otherTeam)->create());
    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $otherTask->companies()->attach($company);

    RelaticleServer::actingAs($this->user)
        ->tool(DetachTaskFromEntitiesTool::class, [
            'id' => $otherTask->id,
            'company_ids' => [$company->id],
        ])
        ->assertHasErrors();

    expect($otherTask->companies()->whereKey($company->id)->exists())->toBeTrue();
});

it('can filter tasks by company_id', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();
    $linkedTask = Task::factory()->recycle([$this->user, $this->team])->create(['title' => 'Linked Task']);
    $linkedTask->companies()->attach($company);
    $unlinkedTask = Task::factory()->recycle([$this->user, $this->team])->create(['title' => 'Unlinked Task']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListTasksTool::class, [
            'company_id' => $company->id,
        ])
        ->assertOk()
        ->assertSee('Linked Task')
        ->assertDontSee('Unlinked Task');
});

describe('team scoping', function (): void {
    beforeEach(function (): void {
        Task::addGlobalScope(new TeamScope);
    });

    it('scopes tasks to current team', function (): void {
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create([
            'team_id' => Team::factory()->create()->id,
            'title' => 'Other Team Task',
        ]));
        $ownTask = Task::factory()->recycle([$this->user, $this->team])->create(['title' => 'Own Team Task']);

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
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot delete a task from another team', function (): void {
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(DeleteTaskTool::class, [
                'id' => $otherTask->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot get a task from another team', function (): void {
        $otherTask = Task::withoutEvents(fn () => Task::factory()->create([
            'team_id' => Team::factory()->create()->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(GetTaskTool::class, [
                'id' => $otherTask->id,
            ])
            ->assertHasErrors(['not found']);
    });
});
