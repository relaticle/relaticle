<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
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

it('creates every record in the batch on a single approval', function (): void {
    $action = makeBatchProposal($this->convId, $this->user, [
        ['title' => 'Batch A'], ['title' => 'Batch B'], ['title' => 'Batch C'],
    ]);

    $resolved = resolve(PendingActionService::class)->approve($action, $this->user);

    expect(Task::query()->where('team_id', $this->user->currentTeam->getKey())->pluck('title')->sort()->values()->all())
        ->toBe(['Batch A', 'Batch B', 'Batch C'])
        ->and($resolved->status)->toBe(PendingActionStatus::Approved)
        ->and($resolved->result_data['count'])->toBe(3)
        ->and($resolved->result_data['ids'])->toHaveCount(3)
        ->and($resolved->result_data['type'])->toBe('task');
});

it('creates nothing when one record in the batch fails (all-or-nothing)', function (): void {
    // title is a NOT NULL column with no default; passing null throws a DB QueryException,
    // which rolls back the outer DB::transaction in approve() leaving zero tasks created.
    $action = makeBatchProposal($this->convId, $this->user, [
        ['title' => 'Good one'],
        ['title' => null],
    ]);

    expect(fn () => resolve(PendingActionService::class)->approve($action, $this->user))
        ->toThrow(QueryException::class);

    expect(Task::query()->where('team_id', $this->user->currentTeam->getKey())->count())->toBe(0)
        ->and($action->fresh()->status)->toBe(PendingActionStatus::Pending);
});
