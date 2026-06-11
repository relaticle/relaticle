<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Task\CreateTaskTool;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    Auth::guard('web')->setUser($this->user);
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);

    $this->convId = '019df900-4444-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function proposeTasks(string $convId, array $records): string
{
    $tool = resolve(CreateTaskTool::class);
    $tool->setConversationId($convId);

    return $tool->handle(new Request(['records' => $records]));
}

it('creates ONE proposal containing all records for a batch', function (): void {
    proposeTasks($this->convId, [
        ['title' => 'Task A'],
        ['title' => 'Task B'],
        ['title' => 'Task C'],
    ]);

    $pending = PendingAction::query()->where('conversation_id', $this->convId)->get();

    expect($pending)->toHaveCount(1)
        ->and($pending->first()->action_data['_batch'])->toBeTrue()
        ->and($pending->first()->action_data['records'])->toHaveCount(3)
        ->and($pending->first()->display_data['summary'])->toBe('Create 3 tasks')
        ->and($pending->first()->display_data['items'])->toHaveCount(3);
});

it('keeps the flat single-record shape when one record is passed', function (): void {
    proposeTasks($this->convId, [['title' => 'Solo task']]);

    $action = PendingAction::query()->where('conversation_id', $this->convId)->firstOrFail();

    expect($action->action_data)->toBe(['title' => 'Solo task'])
        ->and($action->display_data['summary'])->toBe('Create task "Solo task"')
        ->and($action->display_data)->not->toHaveKey('items');
});

it('rejects an empty or oversized batch', function (): void {
    expect(proposeTasks($this->convId, []))->toContain('error')
        ->and(proposeTasks($this->convId, array_fill(0, 26, ['title' => 'X'])))->toContain('error');

    expect(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(0);
});

it('collapses an identical re-proposed batch (job retry idempotency)', function (): void {
    proposeTasks($this->convId, [['title' => 'A'], ['title' => 'B']]);
    proposeTasks($this->convId, [['title' => 'A'], ['title' => 'B']]);

    expect(PendingAction::query()
        ->where('conversation_id', $this->convId)
        ->where('status', PendingActionStatus::Pending)
        ->count())->toBe(1);
});
