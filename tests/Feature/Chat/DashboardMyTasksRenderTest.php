<?php

declare(strict_types=1);

use App\Actions\Task\CompleteTask;
use App\Actions\Task\NotifyTaskAssignees;
use App\Actions\Task\UpdateTask;
use App\Features\OnboardSeed;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\TaskResource;
use App\Models\CustomFieldValue;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

mutates(CompleteTask::class, Dashboard::class, NotifyTaskAssignees::class, UpdateTask::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

it('renders the empty state when the user has no qualifying tasks', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    livewire(Dashboard::class)
        ->assertSee(__('filament/pages/dashboard.tasks.heading'))
        ->assertSee(__('filament/pages/dashboard.tasks.empty.title'))
        ->assertSee(__('filament/pages/dashboard.tasks.empty.description'));
});

it('renders task rows and the count when the user has qualifying tasks', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $dueFieldId = DB::table('custom_fields')
        ->where('tenant_id', $workspace->id)
        ->where('entity_type', 'task')
        ->where('code', 'due_date')
        ->value('id');

    $task = Task::factory()->for($workspace)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);
    CustomFieldValue::query()->create([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => $dueFieldId,
        'tenant_id' => $workspace->id,
        'datetime_value' => now()->subHour(),
    ]);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->assertSeeHtml('role="checkbox"')
        ->assertDontSee(__('filament/pages/dashboard.tasks.empty.title'));
});

it('mounts the createTask action on the page', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    livewire(Dashboard::class)
        ->assertActionExists('createTask');
});

it('edits a task in place from the dashboard', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $task = Task::factory()->for($workspace)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    livewire(Dashboard::class)
        ->assertSeeHtml("mountAction('editTask', { task: '{$task->id}' })")
        ->assertSet('openTaskId', null)
        ->mountAction('editTask', ['task' => $task->id])
        ->assertActionMounted('editTask')
        ->assertSet('openTaskId', $task->id)
        ->assertSchemaStateSet(['title' => 'Ship the widget', 'assignees' => [$user->id]])
        ->setActionData(['title' => 'Ship the gadget'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertSet('openTaskId', null)
        ->assertSee('Ship the gadget')
        ->assertDontSee('Ship the widget');

    expect($task->refresh()->title)->toBe('Ship the gadget');
});

it('leaves the task link to the tasks page for a viewer who cannot edit', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $viewer = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $workspace->users()->attach($viewer, ['role' => 'viewer']);

    $task = Task::factory()->for($workspace)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($viewer);

    $this->actingAs($viewer);
    Filament::setTenant($workspace);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->assertSeeHtml('href="'.e(TaskResource::getUrl('index', ['task' => $task->id], panel: 'app', tenant: $workspace)).'"')
        ->assertDontSeeHtml("mountAction('editTask'")
        ->mountAction('editTask', ['task' => $task->id])
        ->assertActionNotMounted('editTask');
});

it('notifies only the member newly assigned from the dashboard edit', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $added = User::factory()->create();
    $workspace->users()->attach($added, ['role' => 'member']);

    $task = Task::factory()->for($workspace)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    livewire(Dashboard::class)
        ->mountAction('editTask', ['task' => $task->id])
        ->setActionData(['assignees' => [$user->id, $added->id]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($added->notifications()->count())->toBe(1)
        ->and($user->notifications()->count())->toBe(0);
});

it('does not open a task from another workspace for editing', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreign = Task::factory()->for($stranger->currentWorkspace)->create(['title' => 'Not yours']);

    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    livewire(Dashboard::class)
        ->mountAction('editTask', ['task' => $foreign->id])
        ->assertActionNotMounted('editTask');

    expect($foreign->refresh()->title)->toBe('Not yours');
});

it('notifies only the assignees submitted through the dashboard create action', function (): void {
    Notification::fake();

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $intendedAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $concurrentAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $workspace->users()->attach([$intendedAssignee->id, $concurrentAssignee->id], ['role' => 'member']);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    $concurrentAssignmentAdded = false;
    DB::listen(function (QueryExecuted $query) use ($concurrentAssignee, &$concurrentAssignmentAdded): void {
        if ($concurrentAssignmentAdded || ! str_contains($query->sql, 'insert into "task_user"')) {
            return;
        }

        $taskId = DB::table('tasks')->where('title', 'Dashboard notification race')->value('id');

        if (! is_string($taskId)) {
            return;
        }

        $concurrentAssignmentAdded = true;
        DB::table('task_user')->insert([
            'task_id' => $taskId,
            'user_id' => $concurrentAssignee->id,
        ]);
    });

    livewire(Dashboard::class)
        ->callAction('createTask', data: [
            'title' => 'Dashboard notification race',
            'assignees' => [$intendedAssignee->id],
        ])
        ->assertHasNoActionErrors();

    Notification::assertSentTo($intendedAssignee, TaskAssignedNotification::class, fn (TaskAssignedNotification $notification, array $channels): bool => in_array('mail', $channels, true));
    Notification::assertNotSentTo($concurrentAssignee, TaskAssignedNotification::class);
});

it('completes a task from the dashboard and drops it from the list', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $task = Task::factory()->for($workspace)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);

    $this->actingAs($user);
    Filament::setTenant($workspace);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->call('completeTask', $task->id)
        ->assertDontSee('Ship the widget')
        ->assertSee(__('filament/pages/dashboard.tasks.empty.title'));

    $doneId = DB::table('custom_field_options as o')
        ->join('custom_fields as f', 'f.id', '=', 'o.custom_field_id')
        ->where('f.tenant_id', $workspace->id)
        ->where('f.entity_type', 'task')
        ->where('f.code', 'status')
        ->where('o.name', 'Done')
        ->value('o.id');

    expect(DB::table('custom_field_values')->where('entity_id', $task->id)->value('string_value'))
        ->toBe(trim((string) $doneId));
});

it('leaves a task from another workspace untouched', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreign = Task::factory()->for($stranger->currentWorkspace)->create(['title' => 'Not yours']);

    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    livewire(Dashboard::class)->call('completeTask', $foreign->id);

    expect(DB::table('custom_field_values')->where('entity_id', $foreign->id)->count())->toBe(0);
});

it('ignores a task id that no longer resolves', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    livewire(Dashboard::class)
        ->call('completeTask', 'gone')
        ->assertOk()
        ->assertSee(__('filament/pages/dashboard.tasks.empty.title'));
});

it('hides the completion control when the tenant has no Done status option', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $task = Task::factory()->for($workspace)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);

    DB::table('custom_field_options')
        ->whereIn('custom_field_id', DB::table('custom_fields')
            ->where('tenant_id', $workspace->id)
            ->where('entity_type', 'task')
            ->where('code', 'status')
            ->select('id'))
        ->where('name', 'Done')
        ->delete();

    $this->actingAs($user);
    Filament::setTenant($workspace);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->assertDontSeeHtml('role="checkbox"');
});

it('writes the Done option of the task workspace, not the ambient tenant, when the user belongs to both', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $other = User::factory()->withPersonalWorkspace()->create();
    $other->currentWorkspace->users()->attach($user, ['role' => 'member']);

    $task = Task::factory()->for($other->currentWorkspace)->create(['title' => 'Cross-workspace task']);

    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    resolve(CompleteTask::class)->execute($user, $task);

    $written = DB::table('custom_field_values as v')
        ->join('custom_fields as f', 'f.id', '=', 'v.custom_field_id')
        ->where('v.entity_id', $task->id)
        ->where('f.code', 'status')
        ->first(['f.tenant_id', 'v.string_value']);

    $doneOfTaskWorkspace = DB::table('custom_field_options as o')
        ->join('custom_fields as f', 'f.id', '=', 'o.custom_field_id')
        ->where('f.tenant_id', $other->currentWorkspace->getKey())
        ->where('f.entity_type', 'task')->where('f.code', 'status')
        ->where('o.name', 'Done')
        ->value('o.id');

    expect($written->tenant_id)->toBe($other->currentWorkspace->getKey())
        ->and($written->string_value)->toBe($doneOfTaskWorkspace);
});
