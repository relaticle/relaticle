<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;
use Relaticle\Chat\Tools\Task\DeleteTaskTool;

mutates(BaseWriteDeleteTool::class);
mutates(DeleteTaskTool::class);
mutates(PendingActionService::class);

beforeEach(function (): void {
    Bus::fake();

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);
});

it('builds ONE per-item batch proposal holding every requested record id', function (): void {
    $tasks = Task::factory()->count(3)->for($this->user->currentTeam)->create();
    $ids = $tasks->pluck('id')->all();

    $json = app(DeleteTaskTool::class)->handle(new Request(['ids' => $ids]));
    $payload = json_decode($json, true);

    expect(PendingAction::query()->where('user_id', $this->user->getKey())->count())->toBe(1);

    $pending = PendingAction::query()->where('user_id', $this->user->getKey())->firstOrFail();

    expect($pending->action_data['_batch'])->toBeTrue()
        ->and($pending->action_data['records'])->toHaveCount(3)
        ->and(collect($pending->action_data['records'])->pluck('_record_id')->all())->toEqualCanonicalizing($ids)
        ->and($pending->action_data['records'][0]['_model_class'])->toBe(Task::class)
        ->and($pending->action_data)->not->toHaveKey('_record_ids')
        ->and($pending->display_data['summary'])->toContain('3 tasks')
        ->and($pending->display_data['items'])->toHaveCount(3)
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

    expect(collect($pending->action_data['records'])->pluck('_record_id')->all())->toEqualCanonicalizing($mine->pluck('id')->all())
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

it('deletes each approved item and leaves skipped ones, per-item', function (): void {
    $tasks = Task::factory()->count(3)->for($this->user->currentTeam)->create();

    app(DeleteTaskTool::class)->handle(new Request(['ids' => $tasks->pluck('id')->all()]));
    $pending = PendingAction::query()->firstOrFail();
    $records = $pending->action_data['records'];

    $service = app(PendingActionService::class);
    $service->approveItem($pending, $this->user, 0); // delete item 0
    $service->rejectItem($pending, 1);               // skip item 1
    $result = $service->approveItem($pending, $this->user, 2); // delete item 2 -> finalizes

    expect($result['finalized'])->toBeTrue()
        ->and(Task::query()->whereKey($records[1]['_record_id'])->exists())->toBeTrue()
        ->and(Task::query()->whereIn('id', [$records[0]['_record_id'], $records[2]['_record_id']])->count())->toBe(0);
    expect($pending->refresh()->status->value)->toBe('approved');
});

it('fails only the item whose record vanished, leaving siblings deletable', function (): void {
    $tasks = Task::factory()->count(3)->for($this->user->currentTeam)->create();

    app(DeleteTaskTool::class)->handle(new Request(['ids' => $tasks->pluck('id')->all()]));
    $pending = PendingAction::query()->firstOrFail();
    $records = $pending->action_data['records'];

    Task::query()->whereKey($records[1]['_record_id'])->forceDelete();

    $service = app(PendingActionService::class);
    $service->approveItem($pending, $this->user, 0);
    expect(fn () => $service->approveItem($pending, $this->user, 1))->toThrow(RuntimeException::class);
    $service->approveItem($pending, $this->user, 2);

    expect(Task::query()->whereIn('id', [$records[0]['_record_id'], $records[2]['_record_id']])->count())->toBe(0);
});
