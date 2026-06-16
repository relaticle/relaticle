<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Bus::fake();
    $this->user = User::factory()->withPersonalTeam()->create();
    Auth::guard('web')->setUser($this->user);
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);

    $this->convId = '019df900-5555-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function makeBatchProposal(string $convId, User $user, array $records): PendingAction
{
    return PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['_batch' => true, 'records' => $records],
        'display_data' => ['title' => 'Create Tasks', 'summary' => 'Create '.count($records).' tasks', 'items' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

it('refuses a whole-batch approval — a batch resolves only per item', function (): void {
    $action = makeBatchProposal($this->convId, $this->user, [
        ['title' => 'Batch A'], ['title' => 'Batch B'], ['title' => 'Batch C'],
    ]);

    expect(fn () => resolve(PendingActionService::class)->approve($action, $this->user))
        ->toThrow(RuntimeException::class);

    expect(Task::query()->where('team_id', $this->user->currentTeam->getKey())->count())->toBe(0)
        ->and($action->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('approves one batch item without creating the others and stays pending', function (): void {
    $action = makeBatchProposal($this->convId, $this->user, [
        ['title' => 'Item A'], ['title' => 'Item B'], ['title' => 'Item C'],
    ]);

    $result = resolve(PendingActionService::class)->approveItem($action, $this->user, 0);

    expect($result['finalized'])->toBeFalse()
        ->and(Task::query()->where('team_id', $this->user->currentTeam->getKey())->pluck('title')->all())->toBe(['Item A']);

    $fresh = $action->fresh();
    expect($fresh->status)->toBe(PendingActionStatus::Pending)
        ->and($fresh->result_data['items']['0']['status'])->toBe('approved')
        ->and($fresh->result_data['ids'])->toHaveCount(1);
});

it('finalizes the batch without dispatching a continuation after the last item resolves', function (): void {
    $action = makeBatchProposal($this->convId, $this->user, [
        ['title' => 'Keep 1'], ['title' => 'Skip me'], ['title' => 'Keep 2'],
    ]);
    $service = resolve(PendingActionService::class);

    $service->approveItem($action, $this->user, 0);
    $service->rejectItem($action, 1);

    $last = $service->approveItem($action, $this->user, 2);

    expect($last['finalized'])->toBeTrue();
    $fresh = $action->fresh();
    expect($fresh->status)->toBe(PendingActionStatus::Approved)
        ->and($fresh->result_data['count'])->toBe(2)
        ->and($fresh->result_data['ids'])->toHaveCount(2)
        ->and($fresh->result_data['type'])->toBe('task')
        ->and($fresh->result_data['items']['1']['status'])->toBe('rejected')
        ->and(Task::query()->where('team_id', $this->user->currentTeam->getKey())->pluck('title')->sort()->values()->all())
        ->toBe(['Keep 1', 'Keep 2']);
});

it('marks the batch rejected when every item is skipped', function (): void {
    $action = makeBatchProposal($this->convId, $this->user, [['title' => 'X'], ['title' => 'Y']]);
    $service = resolve(PendingActionService::class);

    $service->rejectItem($action, 0);
    $service->rejectItem($action, 1);

    expect($action->fresh()->status)->toBe(PendingActionStatus::Rejected)
        ->and(Task::query()->where('team_id', $this->user->currentTeam->getKey())->count())->toBe(0);
});

it('is idempotent — re-approving the same item does not double-create', function (): void {
    $action = makeBatchProposal($this->convId, $this->user, [['title' => 'Once'], ['title' => 'Two']]);
    $service = resolve(PendingActionService::class);

    $service->approveItem($action, $this->user, 0);
    $service->approveItem($action, $this->user, 0);

    expect(Task::query()->where('team_id', $this->user->currentTeam->getKey())->where('title', 'Once')->count())->toBe(1)
        ->and($action->fresh()->result_data['ids'])->toHaveCount(1);
});

it('throws when approving an out-of-range item index on a batch', function (): void {
    $batch = makeBatchProposal($this->convId, $this->user, [['title' => 'A']]);

    expect(fn () => resolve(PendingActionService::class)->approveItem($batch, $this->user, 5))
        ->toThrow(RuntimeException::class);
});

it('throws when calling approveItem on a non-batch proposal', function (): void {
    $flat = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Flat'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    expect(fn () => resolve(PendingActionService::class)->approveItem($flat, $this->user, 0))
        ->toThrow(RuntimeException::class);
});

it('finalizes to Approved when the last resolution is a skip but earlier items were created', function (): void {
    $action = makeBatchProposal($this->convId, $this->user, [
        ['title' => 'Made A'], ['title' => 'Made B'], ['title' => 'Skipped C'],
    ]);
    $service = resolve(PendingActionService::class);

    $service->approveItem($action, $this->user, 0);
    $service->approveItem($action, $this->user, 1);

    $last = $service->rejectItem($action, 2);

    expect($last['finalized'])->toBeTrue();
    $fresh = $action->fresh();
    expect($fresh->status)->toBe(PendingActionStatus::Approved)
        ->and($fresh->result_data['count'])->toBe(2)
        ->and($fresh->result_data['ids'])->toHaveCount(2)
        ->and($fresh->result_data['items']['2']['status'])->toBe('rejected')
        ->and(Task::query()->where('team_id', $this->user->currentTeam->getKey())->pluck('title')->sort()->values()->all())
        ->toBe(['Made A', 'Made B']);
});

it('dispatches no continuation on a single approve and persists the record', function (): void {
    $action = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Single Approve'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $resolved = resolve(PendingActionService::class)->approve($action, $this->user);

    expect($resolved->status)->toBe(PendingActionStatus::Approved)
        ->and($resolved->result_data['type'])->toBe('task')
        ->and(Task::query()->where('team_id', $this->user->currentTeam->getKey())->where('title', 'Single Approve')->count())->toBe(1);
});

it('dispatches no continuation on a single reject and creates nothing', function (): void {
    $action = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Single Reject'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $resolved = resolve(PendingActionService::class)->reject($action);

    expect($resolved->status)->toBe(PendingActionStatus::Rejected)
        ->and(Task::query()->where('team_id', $this->user->currentTeam->getKey())->where('title', 'Single Reject')->count())->toBe(0);
});

it('rejecting an already-resolved action throws', function (): void {
    $action = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Reject Once'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
    $service = resolve(PendingActionService::class);

    $service->reject($action);

    expect(fn () => $service->reject($action->refresh()))->toThrow(RuntimeException::class);
});

it('approving an expired action throws and creates nothing', function (): void {
    $action = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Expired'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->subMinute(),
    ]);

    expect(fn () => resolve(PendingActionService::class)->approve($action, $this->user))
        ->toThrow(RuntimeException::class, 'This action has expired');

    expect(Task::query()->where('team_id', $this->user->currentTeam->getKey())->count())->toBe(0);
});

it('approving an already-resolved action throws', function (): void {
    $action = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => 'App\\Actions\\Task\\CreateTask',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Done'],
        'display_data' => [],
        'status' => PendingActionStatus::Approved,
        'resolved_at' => now(),
        'expires_at' => now()->addMinutes(15),
    ]);

    expect(fn () => resolve(PendingActionService::class)->approve($action, $this->user))
        ->toThrow(RuntimeException::class, 'This action has already been resolved');
});
