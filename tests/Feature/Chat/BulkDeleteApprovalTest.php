<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Task\DeleteTaskTool;

mutates(PendingActionService::class);

beforeEach(function (): void {
    Bus::fake();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
});

it('restores every record when a bulk delete is undone', function (): void {
    $tasks = Task::factory()->count(3)->for($this->user->currentTeam)->create();

    app(DeleteTaskTool::class)->handle(new Request(['ids' => $tasks->pluck('id')->all()]));
    $pending = PendingAction::query()->firstOrFail();

    $service = app(PendingActionService::class);
    $service->approve($pending, $this->user);

    foreach ($tasks as $task) {
        expect(Task::query()->whereKey($task->getKey())->exists())->toBeFalse();
    }

    $service->restore($pending->refresh(), $this->user);

    foreach ($tasks as $task) {
        expect(Task::query()->whereKey($task->getKey())->exists())->toBeTrue();
    }
    expect($pending->refresh()->status->value)->toBe('restored');
});
