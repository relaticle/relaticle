<?php

declare(strict_types=1);

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
        ->assertDontSee(__('filament/pages/dashboard.tasks.empty.title'));
});

it('mounts the createTask action on the page', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    livewire(Dashboard::class)
        ->assertActionExists('createTask');
});
