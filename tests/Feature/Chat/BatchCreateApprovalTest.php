<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Features\OnboardSeed;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Events\PendingActionResolved;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\ProposalEditor;

uses(LazilyRefreshDatabase::class);

mutates(PendingActionService::class);

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
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
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
        'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['_batch' => true, 'records' => $records],
        'display_data' => ['title' => 'Create Tasks', 'summary' => 'Create '.count($records).' tasks', 'items' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

function makeSingleProposal(string $convId, User $user, string $title): PendingAction
{
    return PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $convId,
        'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => $title],
        'display_data' => [],
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
    $service->rejectItem($action, $this->user, 1);

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

    $service->rejectItem($action, $this->user, 0);
    $service->rejectItem($action, $this->user, 1);

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
        'action_class' => CreateTask::class,
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

    $last = $service->rejectItem($action, $this->user, 2);

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
    $action = makeSingleProposal($this->convId, $this->user, 'Single Approve');

    $resolved = resolve(PendingActionService::class)->approve($action, $this->user);

    expect($resolved->status)->toBe(PendingActionStatus::Approved)
        ->and($resolved->result_data['type'])->toBe('task')
        ->and(Task::query()->where('team_id', $this->user->currentTeam->getKey())->where('title', 'Single Approve')->count())->toBe(1);
});

it('dispatches no continuation on a single reject and creates nothing', function (): void {
    $action = makeSingleProposal($this->convId, $this->user, 'Single Reject');

    $resolved = resolve(PendingActionService::class)->reject($action, $this->user);

    expect($resolved->status)->toBe(PendingActionStatus::Rejected)
        ->and(Task::query()->where('team_id', $this->user->currentTeam->getKey())->where('title', 'Single Reject')->count())->toBe(0);
});

it('rejecting an already-resolved action throws', function (): void {
    $action = makeSingleProposal($this->convId, $this->user, 'Reject Once');
    $service = resolve(PendingActionService::class);

    $service->reject($action, $this->user);

    expect(fn () => $service->reject($action->refresh(), $this->user))->toThrow(RuntimeException::class);
});

it('approving an expired action throws and creates nothing', function (): void {
    $action = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'action_class' => CreateTask::class,
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
        'action_class' => CreateTask::class,
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

/**
 * F1: every resolution path (single approve/reject, and per-item approveItem/
 * rejectItem for a batch) broadcasts `pending_action.resolved` on the
 * conversation's private channel, so a second open tab reconciles a proposal
 * resolved elsewhere instead of showing stale Approve/Reject buttons on an
 * action that has already been decided. approve()/reject() are NOT the only
 * two resolution paths: a batch resolves per item through approveItem()/
 * rejectItem(), each in its own transaction, so those two must broadcast too.
 */
it('broadcasts pending_action.resolved on a single approve', function (): void {
    Event::fake([PendingActionResolved::class]);
    $action = makeSingleProposal($this->convId, $this->user, 'Broadcast Approve');

    resolve(PendingActionService::class)->approve($action, $this->user);

    Event::assertDispatched(fn (PendingActionResolved $event): bool => $event->conversationId === $this->convId
        && $event->pendingActionId === $action->getKey()
        && $event->status === 'approved'
        && $event->index === null
        && $event->finalized
        && $event->broadcastOn()[0]->name === "private-chat.conversation.{$this->convId}"
        && $event->broadcastAs() === 'pending_action.resolved');
});

it('broadcasts pending_action.resolved on a single reject', function (): void {
    Event::fake([PendingActionResolved::class]);
    $action = makeSingleProposal($this->convId, $this->user, 'Broadcast Reject');

    resolve(PendingActionService::class)->reject($action, $this->user);

    Event::assertDispatched(fn (PendingActionResolved $event): bool => $event->conversationId === $this->convId
        && $event->pendingActionId === $action->getKey()
        && $event->status === 'rejected'
        && $event->index === null
        && $event->finalized
        && $event->broadcastOn()[0]->name === "private-chat.conversation.{$this->convId}"
        && $event->broadcastAs() === 'pending_action.resolved');
});

it('broadcasts pending_action.resolved with the item index on a batch approveItem, unfinalized', function (): void {
    Event::fake([PendingActionResolved::class]);
    $action = makeBatchProposal($this->convId, $this->user, [['title' => 'Item A'], ['title' => 'Item B']]);

    resolve(PendingActionService::class)->approveItem($action, $this->user, 0);

    Event::assertDispatched(fn (PendingActionResolved $event): bool => $event->conversationId === $this->convId
        && $event->pendingActionId === $action->getKey()
        && $event->status === 'approved'
        && $event->index === 0
        && $event->finalized === false
        && $event->broadcastOn()[0]->name === "private-chat.conversation.{$this->convId}"
        && $event->broadcastAs() === 'pending_action.resolved');
});

it('broadcasts pending_action.resolved as finalized on the batch\'s last item', function (): void {
    Event::fake([PendingActionResolved::class]);
    $action = makeBatchProposal($this->convId, $this->user, [['title' => 'Item A'], ['title' => 'Item B']]);
    $service = resolve(PendingActionService::class);

    $service->approveItem($action, $this->user, 0);
    $service->approveItem($action, $this->user, 1);

    Event::assertDispatched(fn (PendingActionResolved $event): bool => $event->conversationId === $this->convId
        && $event->pendingActionId === $action->getKey()
        && $event->status === 'approved'
        && $event->index === 1
        && $event->finalized
        && $event->broadcastOn()[0]->name === "private-chat.conversation.{$this->convId}"
        && $event->broadcastAs() === 'pending_action.resolved');
});

it('broadcasts pending_action.resolved with status rejected on a batch rejectItem', function (): void {
    Event::fake([PendingActionResolved::class]);
    $action = makeBatchProposal($this->convId, $this->user, [['title' => 'Item A'], ['title' => 'Item B']]);

    resolve(PendingActionService::class)->rejectItem($action, $this->user, 0);

    Event::assertDispatched(fn (PendingActionResolved $event): bool => $event->conversationId === $this->convId
        && $event->pendingActionId === $action->getKey()
        && $event->status === 'rejected'
        && $event->index === 0
        && $event->finalized === false
        && $event->broadcastOn()[0]->name === "private-chat.conversation.{$this->convId}"
        && $event->broadcastAs() === 'pending_action.resolved');
});

it('reports the item\'s real stored status when approveItem is called again on an already-rejected item', function (): void {
    Event::fake([PendingActionResolved::class]);
    $action = makeBatchProposal($this->convId, $this->user, [['title' => 'Item A'], ['title' => 'Item B']]);
    $service = resolve(PendingActionService::class);

    $service->rejectItem($action, $this->user, 0);
    $service->approveItem($action, $this->user, 0);

    // The idempotent no-op path must report the item's REAL status ('rejected',
    // set by the first call) rather than assuming 'approved' just because
    // approveItem() is the method that was called the second time. Both
    // dispatched events share pendingActionId/index/status = 0/'rejected';
    // assertDispatched alone would pass on the FIRST event no matter what the
    // second (the no-op path under test) reports, so assertNotDispatched is
    // the assertion that actually discriminates.
    Event::assertDispatchedTimes(PendingActionResolved::class, 2);
    Event::assertDispatched(fn (PendingActionResolved $event): bool => $event->pendingActionId === $action->getKey()
        && $event->index === 0
        && $event->status === 'rejected'
        && $event->broadcastOn()[0]->name === "private-chat.conversation.{$this->convId}"
        && $event->broadcastAs() === 'pending_action.resolved');
    Event::assertNotDispatched(fn (PendingActionResolved $event): bool => $event->pendingActionId === $action->getKey()
        && $event->index === 0
        && $event->status === 'approved');
    expect(Task::query()->where('team_id', $this->user->currentTeam->getKey())->count())->toBe(0);
});

it('does not broadcast when the pending action has no conversation_id', function (): void {
    Event::fake([PendingActionResolved::class]);
    $action = PendingAction::query()->create([
        'team_id' => $this->user->currentTeam->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => null,
        'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'No Conversation'],
        'display_data' => [],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    resolve(PendingActionService::class)->approve($action, $this->user);

    Event::assertNotDispatched(PendingActionResolved::class);
});

it('refuses to approve a proposal belonging to another team', function (): void {
    $proposal = makeSingleProposal($this->convId, $this->user, 'Owned by my team');

    $outsider = User::factory()->withPersonalTeam()->create();

    expect(fn (): PendingAction => resolve(PendingActionService::class)->approve($proposal, $outsider))
        ->toThrow(RuntimeException::class);

    expect(Task::query()->where('title', 'Owned by my team')->exists())->toBeFalse()
        ->and($proposal->fresh()->status)->toBe(PendingActionStatus::Pending);
});

it('refuses to approve a batch item belonging to another team', function (): void {
    $proposal = makeBatchProposal($this->convId, $this->user, [['title' => 'Batch item A']]);

    $outsider = User::factory()->withPersonalTeam()->create();

    expect(fn (): array => resolve(PendingActionService::class)->approveItem($proposal, $outsider, 0))
        ->toThrow(RuntimeException::class);

    expect(Task::query()->where('title', 'Batch item A')->exists())->toBeFalse();
});

it('approves a single proposal twice without writing the record twice', function (): void {
    $proposal = makeSingleProposal($this->convId, $this->user, 'Only once please');
    $service = resolve(PendingActionService::class);

    $service->approve($proposal, $this->user);

    expect(fn (): PendingAction => $service->approve($proposal->fresh(), $this->user))
        ->toThrow(RuntimeException::class);

    expect(Task::query()->where('title', 'Only once please')->count())->toBe(1);
});

it('refuses every mutating entry point when the actor belongs to another workspace', function (): void {
    $action = makeBatchProposal($this->convId, $this->user, [
        ['name' => 'Task A'],
        ['name' => 'Task B'],
    ]);

    $outsider = User::factory()->withPersonalTeam()->create();
    $service = resolve(PendingActionService::class);

    $calls = [
        'approve' => fn (): mixed => $service->approve($action, $outsider),
        'approveItem' => fn (): mixed => $service->approveItem($action, $outsider, 0),
        'reject' => fn (): mixed => $service->reject($action, $outsider),
        'rejectItem' => fn (): mixed => $service->rejectItem($action, $outsider, 0),
        'cancelStep' => fn (): mixed => $service->cancelStep($action, $outsider, (string) $action->getKey()),
        'applyEdit' => fn (): mixed => resolve(ProposalEditor::class)
            ->applyEdit($action, $outsider, ['name' => 'Renamed'], 0),
    ];

    foreach ($calls as $name => $call) {
        expect($call)->toThrow(RuntimeException::class, 'This action belongs to another workspace.', "{$name}() let an outsider through");
    }

    expect($action->refresh()->status)->toBe(PendingActionStatus::Pending)
        ->and(Task::query()->count())->toBe(0);
});

it('gives the user a full day to decide a proposal', function (): void {
    $this->freezeTime();

    $action = resolve(PendingActionService::class)->createProposal(
        user: $this->user,
        conversationId: $this->convId,
        actionClass: CreateTask::class,
        operation: PendingActionOperation::Create,
        entityType: 'task',
        actionData: ['title' => 'Decide tomorrow'],
        displayData: ['summary' => 'Create task "Decide tomorrow"'],
    );

    expect($action->expires_at->format('Y-m-d H:i:s'))->toBe(now()->addMinutes(1440)->format('Y-m-d H:i:s'));
});
