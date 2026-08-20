<?php

declare(strict_types=1);

use App\Filament\Components\Forms\TeamMemberSelect;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;

mutates(TeamMemberSelect::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('still saves task assignees through the pivot after the migration', function (): void {
    $mate = User::factory()->create(['name' => 'Mate Member']);
    $this->team->users()->attach($mate, ['role' => 'editor']);

    livewire(ManageTasks::class)
        ->callAction('create', [
            'title' => 'Picker regression task',
            'assignees' => [$mate->getKey()],
        ])
        ->assertHasNoActionErrors();

    $task = Task::query()->where('title', 'Picker regression task')->firstOrFail();

    expect($task->assignees->pluck('id')->all())->toContain($mate->getKey());
});
