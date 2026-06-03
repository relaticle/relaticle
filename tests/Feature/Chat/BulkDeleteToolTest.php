<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;
use Relaticle\Chat\Tools\Task\DeleteTaskTool;

mutates(BaseWriteDeleteTool::class);
mutates(DeleteTaskTool::class);
mutates(PendingActionService::class);

beforeEach(function (): void {
    Bus::fake([ContinueChatMessage::class]);

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
});

it('builds ONE proposal holding every requested record id', function (): void {
    $tasks = Task::factory()->count(3)->for($this->user->currentTeam)->create();
    $ids = $tasks->pluck('id')->all();

    $json = app(DeleteTaskTool::class)->handle(new Request(['ids' => $ids]));
    $payload = json_decode($json, true);

    expect(PendingAction::query()->where('user_id', $this->user->getKey())->count())->toBe(1);

    $pending = PendingAction::query()->where('user_id', $this->user->getKey())->firstOrFail();

    expect($pending->action_data['_record_ids'])->toEqualCanonicalizing($ids)
        ->and($pending->action_data['_model_class'])->toBe(Task::class)
        ->and($pending->action_data)->not->toHaveKey('_record_id')
        ->and($pending->display_data['summary'])->toContain('3 tasks')
        ->and($pending->display_data['fields'])->toHaveCount(3)
        ->and($payload['operation'])->toBe('delete')
        ->and($payload['data']['ids'])->toEqualCanonicalizing($ids)
        ->and($payload['meta']['agent_should_stop'])->toBeTrue();
});

it('treats a single-element ids array as one record (_record_ids with one entry, Name field)', function (): void {
    $task = Task::factory()->for($this->user->currentTeam)->create(['title' => 'Solo']);

    app(DeleteTaskTool::class)->handle(new Request(['ids' => [$task->getKey()]]));

    $pending = PendingAction::query()->where('user_id', $this->user->getKey())->firstOrFail();

    expect($pending->action_data['_record_ids'])->toBe([$task->getKey()])
        ->and($pending->action_data)->not->toHaveKey('_record_id')
        ->and($pending->display_data['summary'])->toBe('Delete Task "Solo"')
        ->and($pending->display_data['fields'])->toHaveCount(1)
        ->and($pending->display_data['fields'][0]['label'])->toBe('Name');
});

it('skips ids that are missing or in another team and reports them, proposing the rest', function (): void {
    $mine = Task::factory()->count(2)->for($this->user->currentTeam)->create();
    $otherTeamUser = User::factory()->withPersonalTeam()->create();
    $foreign = Task::factory()->for($otherTeamUser->currentTeam)->create();

    $ids = [...$mine->pluck('id')->all(), $foreign->getKey(), 'does-not-exist'];

    $json = app(DeleteTaskTool::class)->handle(new Request(['ids' => $ids]));
    $payload = json_decode($json, true);

    $pending = PendingAction::query()->where('user_id', $this->user->getKey())->firstOrFail();

    expect($pending->action_data['_record_ids'])->toEqualCanonicalizing($mine->pluck('id')->all())
        ->and($payload['skipped'])->toEqualCanonicalizing([$foreign->getKey(), 'does-not-exist']);
});

it('returns an error and creates no proposal when no requested id is valid', function (): void {
    $json = app(DeleteTaskTool::class)->handle(new Request(['ids' => ['nope-1', 'nope-2']]));
    $payload = json_decode($json, true);

    expect($payload)->toHaveKey('error')
        ->and(PendingAction::query()->where('user_id', $this->user->getKey())->count())->toBe(0);
});

it('returns an error when ids is empty or missing', function (): void {
    $payload = json_decode(app(DeleteTaskTool::class)->handle(new Request(['ids' => []])), true);
    expect($payload)->toHaveKey('error');
});

it('deletes every record in the proposal on approval (all-or-nothing)', function (): void {
    $tasks = Task::factory()->count(3)->for($this->user->currentTeam)->create();

    app(DeleteTaskTool::class)->handle(new Request(['ids' => $tasks->pluck('id')->all()]));
    $pending = PendingAction::query()->firstOrFail();

    app(PendingActionService::class)->approve($pending, $this->user);

    foreach ($tasks as $task) {
        expect(Task::query()->whereKey($task->getKey())->exists())->toBeFalse();
    }
    expect($pending->refresh()->status->value)->toBe('approved');
});

it('rolls back the whole batch if one record disappears before approval', function (): void {
    $tasks = Task::factory()->count(3)->for($this->user->currentTeam)->create();

    app(DeleteTaskTool::class)->handle(new Request(['ids' => $tasks->pluck('id')->all()]));
    $pending = PendingAction::query()->firstOrFail();

    $tasks[1]->forceDelete();

    expect(fn () => app(PendingActionService::class)->approve($pending, $this->user))
        ->toThrow(RuntimeException::class);

    expect(Task::query()->whereKey($tasks[0]->getKey())->exists())->toBeTrue()
        ->and(Task::query()->whereKey($tasks[2]->getKey())->exists())->toBeTrue();
});
