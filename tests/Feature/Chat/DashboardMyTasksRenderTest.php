<?php

declare(strict_types=1);

use App\Actions\Task\CompleteTask;
use App\Features\OnboardSeed;
use App\Filament\Pages\Dashboard;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\CustomFields\Models\CustomFieldValue;

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
