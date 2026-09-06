<?php

declare(strict_types=1);

use App\Actions\Task\CompleteTask;
use App\Actions\Task\NotifyTaskAssignees;
use App\Features\OnboardSeed;
use App\Filament\Pages\Dashboard;
use App\Mail\TaskAssignedMail;
use App\Models\CustomFieldValue;
use App\Models\Task;
use App\Models\User;
use App\Support\OptionsInCategory;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\MyTasksService;
use Relaticle\CustomFields\Enums\OptionCategory;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(CompleteTask::class, Dashboard::class, MyTasksService::class, NotifyTaskAssignees::class, OptionsInCategory::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

it('renders the empty state when the user has no qualifying tasks', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    livewire(Dashboard::class)
        ->assertSee(__('filament/pages/dashboard.tasks.heading'))
        ->assertSee(__('filament/pages/dashboard.tasks.empty.title'))
        ->assertSee(__('filament/pages/dashboard.tasks.empty.description'));
});

it('renders task rows and the count when the user has qualifying tasks', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $dueFieldId = DB::table('custom_fields')
        ->where('tenant_id', $team->id)
        ->where('entity_type', 'task')
        ->where('code', 'due_date')
        ->value('id');

    $task = Task::factory()->for($team)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);
    CustomFieldValue::query()->create([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => $dueFieldId,
        'tenant_id' => $team->id,
        'datetime_value' => now()->subHour(),
    ]);

    $this->actingAs($user);
    Filament::setTenant($team);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->assertSeeHtml('role="checkbox"')
        ->assertDontSee(__('filament/pages/dashboard.tasks.empty.title'));
});

it('mounts the createTask action on the page', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    livewire(Dashboard::class)
        ->assertActionExists('createTask');
});

it('notifies only the assignees submitted through the dashboard create action', function (): void {
    Mail::fake();

    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $intendedAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $concurrentAssignee = User::factory()->create([
        'notification_preferences' => ['task_assigned' => ['email' => true]],
    ]);
    $team->users()->attach([$intendedAssignee->id, $concurrentAssignee->id], ['role' => 'editor']);

    $this->actingAs($user);
    Filament::setTenant($team);

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

    defer()->invoke();

    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($intendedAssignee->email));
    Mail::assertNotQueued(TaskAssignedMail::class, fn (TaskAssignedMail $mail): bool => $mail->hasTo($concurrentAssignee->email));
});

it('completes a task from the dashboard and drops it from the list', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $task = Task::factory()->for($team)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);

    $this->actingAs($user);
    Filament::setTenant($team);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->call('completeTask', $task->id)
        ->assertDontSee('Ship the widget')
        ->assertSee(__('filament/pages/dashboard.tasks.empty.title'));

    $doneId = DB::table('custom_field_options as o')
        ->join('custom_fields as f', 'f.id', '=', 'o.custom_field_id')
        ->where('f.tenant_id', $team->id)
        ->where('f.entity_type', 'task')
        ->where('f.code', 'status')
        ->where('o.name', 'Done')
        ->value('o.id');

    expect(DB::table('custom_field_values')->where('entity_id', $task->id)->value('string_value'))
        ->toBe(trim((string) $doneId));
});

it('leaves a task from another team untouched', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $stranger = User::factory()->withPersonalTeam()->create();
    $foreign = Task::factory()->for($stranger->currentTeam)->create(['title' => 'Not yours']);

    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    livewire(Dashboard::class)->call('completeTask', $foreign->id);

    expect(DB::table('custom_field_values')->where('entity_id', $foreign->id)->count())->toBe(0);
});

it('ignores a task id that no longer resolves', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    livewire(Dashboard::class)
        ->call('completeTask', 'gone')
        ->assertOk()
        ->assertSee(__('filament/pages/dashboard.tasks.empty.title'));
});

it('hides the completion control when the tenant has no Done status option', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $task = Task::factory()->for($team)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);

    DB::table('custom_field_options')
        ->whereIn('custom_field_id', DB::table('custom_fields')
            ->where('tenant_id', $team->id)
            ->where('entity_type', 'task')
            ->where('code', 'status')
            ->select('id'))
        ->where('name', 'Done')
        ->delete();

    $this->actingAs($user);
    Filament::setTenant($team);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->assertDontSeeHtml('role="checkbox"');
});

it('writes the Done option of the task team, not the ambient tenant, when the user belongs to both', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->withPersonalTeam()->create();
    $other->currentTeam->users()->attach($user, ['role' => 'editor']);

    $task = Task::factory()->for($other->currentTeam)->create(['title' => 'Cross-team task']);

    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    resolve(CompleteTask::class)->execute($user, $task);

    $written = DB::table('custom_field_values as v')
        ->join('custom_fields as f', 'f.id', '=', 'v.custom_field_id')
        ->where('v.entity_id', $task->id)
        ->where('f.code', 'status')
        ->first(['f.tenant_id', 'v.string_value']);

    $doneOfTaskTeam = DB::table('custom_field_options as o')
        ->join('custom_fields as f', 'f.id', '=', 'o.custom_field_id')
        ->where('f.tenant_id', $other->currentTeam->getKey())
        ->where('f.entity_type', 'task')->where('f.code', 'status')
        ->where('o.name', 'Done')
        ->value('o.id');

    expect($written->tenant_id)->toBe($other->currentTeam->getKey())
        ->and($written->string_value)->toBe($doneOfTaskTeam);
});

it('completes a task through a renamed completed status option', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $task = Task::factory()->for($team)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);

    $completedId = trim((string) DB::table('custom_field_options as o')
        ->join('custom_fields as f', 'f.id', '=', 'o.custom_field_id')
        ->where('f.tenant_id', $team->id)
        ->where('f.entity_type', 'task')
        ->where('f.code', 'status')
        ->where('o.settings->category', OptionCategory::Completed->value)
        ->value('o.id'));

    DB::table('custom_field_options')->where('id', $completedId)->update(['name' => 'Shipped']);

    $this->actingAs($user);
    Filament::setTenant($team);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->call('completeTask', $task->id)
        ->assertDontSee('Ship the widget')
        ->assertSee(__('filament/pages/dashboard.tasks.empty.title'));

    expect(DB::table('custom_field_values')->where('entity_id', $task->id)->value('string_value'))
        ->toBe($completedId);
});

it('hides the completion control when no status option carries the completed category', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $task = Task::factory()->for($team)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);

    DB::table('custom_field_options')
        ->whereIn('custom_field_id', DB::table('custom_fields')
            ->where('tenant_id', $team->id)
            ->where('entity_type', 'task')
            ->where('code', 'status')
            ->select('id'))
        ->update(['settings' => json_encode(['color' => null, 'category' => null])]);

    $this->actingAs($user);
    Filament::setTenant($team);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->assertDontSeeHtml('role="checkbox"');
});

function taskStatusFieldId(string $teamId): string
{
    return trim((string) DB::table('custom_fields')
        ->where('tenant_id', $teamId)
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->value('id'));
}

function taskStatusOptionId(string $statusFieldId, ?OptionCategory $category): string
{
    return trim((string) DB::table('custom_field_options')
        ->where('custom_field_id', $statusFieldId)
        ->where('settings->category', $category?->value)
        ->value('id'));
}

function addTaskStatusOption(string $teamId, string $statusFieldId, string $name, OptionCategory $category, int $sortOrder): string
{
    $id = (string) Str::ulid();

    DB::table('custom_field_options')->insert([
        'id' => $id,
        'tenant_id' => $teamId,
        'custom_field_id' => $statusFieldId,
        'name' => $name,
        'sort_order' => $sortOrder,
        'settings' => json_encode(['color' => null, 'category' => $category->value]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function setTaskStatus(Task $task, string $statusFieldId, string $optionId): void
{
    CustomFieldValue::query()->create([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => $statusFieldId,
        'tenant_id' => $task->team_id,
        'string_value' => $optionId,
    ]);
}

it('keeps listing a finished task and warns when no status option is categorised', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $statusFieldId = taskStatusFieldId($team->id);
    $doneId = taskStatusOptionId($statusFieldId, OptionCategory::Completed);

    DB::table('custom_field_options')
        ->where('custom_field_id', $statusFieldId)
        ->update(['settings' => json_encode(['color' => null, 'category' => null])]);

    $task = Task::factory()->for($team)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);
    setTaskStatus($task, $statusFieldId, $doneId);

    Log::spy();

    $this->actingAs($user);
    Filament::setTenant($team);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->assertDontSeeHtml('role="checkbox"');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $context['team_id'] === $team->id
            && $context['field_code'] === 'status');
});

it('refuses to complete a task when no status option carries the completed category', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    DB::table('custom_field_options')
        ->where('custom_field_id', taskStatusFieldId($team->id))
        ->update(['settings' => json_encode(['color' => null, 'category' => null])]);

    $task = Task::factory()->for($team)->create(['title' => 'Ship the widget']);
    $task->assignees()->attach($user);

    $this->actingAs($user);
    Filament::setTenant($team);

    expect(fn () => resolve(CompleteTask::class)->execute($user, $task))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(422));
});

it('hides a cancelled task from the list without completing it', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $statusFieldId = taskStatusFieldId($team->id);
    $wontDoId = addTaskStatusOption($team->id, $statusFieldId, "Won't do", OptionCategory::Cancelled, 4);

    $abandoned = Task::factory()->for($team)->create(['title' => 'Abandon the widget']);
    $abandoned->assignees()->attach($user);
    setTaskStatus($abandoned, $statusFieldId, $wontDoId);

    $open = Task::factory()->for($team)->create(['title' => 'Ship the widget']);
    $open->assignees()->attach($user);

    $this->actingAs($user);
    Filament::setTenant($team);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->assertDontSee('Abandon the widget');
});

it('excludes both completed statuses and completes into the first by sort order', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $statusFieldId = taskStatusFieldId($team->id);
    $doneId = taskStatusOptionId($statusFieldId, OptionCategory::Completed);
    $shippedId = addTaskStatusOption($team->id, $statusFieldId, 'Shipped', OptionCategory::Completed, 5);

    $shipped = Task::factory()->for($team)->create(['title' => 'Already shipped']);
    $shipped->assignees()->attach($user);
    setTaskStatus($shipped, $statusFieldId, $shippedId);

    $open = Task::factory()->for($team)->create(['title' => 'Ship the widget']);
    $open->assignees()->attach($user);

    $this->actingAs($user);
    Filament::setTenant($team);

    livewire(Dashboard::class)
        ->assertSee('Ship the widget')
        ->assertDontSee('Already shipped')
        ->call('completeTask', $open->id)
        ->assertDontSee('Ship the widget');

    expect(DB::table('custom_field_values')->where('entity_id', $open->id)->value('string_value'))
        ->toBe($doneId);
});
