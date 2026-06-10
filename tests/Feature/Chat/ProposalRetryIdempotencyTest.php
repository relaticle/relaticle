<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Task\CreateTaskTool;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    Auth::guard('web')->setUser($this->user);
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);

    $this->convId = '019df900-0000-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function proposeTask(string $convId, array $input): PendingAction
{
    $tool = resolve(CreateTaskTool::class);
    $tool->setConversationId($convId);
    $tool->handle(new Request($input));

    return PendingAction::query()
        ->where('conversation_id', $convId)
        ->orderByDesc('id')
        ->firstOrFail();
}

it('does not create a duplicate pending proposal when an identical one is re-proposed (job retry)', function (): void {
    // A continuation job that creates a proposal mid-stream and then hits a transient 429
    // is retried from the top, re-emitting the SAME tool call with identical args. That
    // must NOT accumulate duplicate proposal cards (F-2).
    proposeTask($this->convId, ['title' => 'Schedule product demo with Acme Corp']);
    proposeTask($this->convId, ['title' => 'Schedule product demo with Acme Corp']);

    expect(PendingAction::query()
        ->where('conversation_id', $this->convId)
        ->where('status', PendingActionStatus::Pending)
        ->count())->toBe(1);
});

it('returns the same proposal id on an identical re-proposal', function (): void {
    $service = resolve(PendingActionService::class);

    $first = $service->createProposal(
        user: $this->user, conversationId: $this->convId,
        actionClass: CreateTask::class,
        operation: PendingActionOperation::Create,
        entityType: 'task', actionData: ['title' => 'Dup'], displayData: ['title' => 'Dup'],
    );
    $second = $service->createProposal(
        user: $this->user, conversationId: $this->convId,
        actionClass: CreateTask::class,
        operation: PendingActionOperation::Create,
        entityType: 'task', actionData: ['title' => 'Dup'], displayData: ['title' => 'Dup'],
    );

    expect($second->getKey())->toBe($first->getKey());
});

it('does NOT dedupe genuinely different proposals', function (): void {
    proposeTask($this->convId, ['title' => 'Task A']);
    proposeTask($this->convId, ['title' => 'Task B']);

    expect(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(2);
});

it('does NOT collapse a new proposal into an already-resolved identical one', function (): void {
    // Legit "create two identical tasks": approve the first, then the continuation proposes an
    // identical second. The approved one must not absorb the new pending proposal.
    Bus::fake(); // swallow the continuation dispatched by approve()
    $first = proposeTask($this->convId, ['title' => 'Same Title']);
    resolve(PendingActionService::class)->approve($first, $this->user);

    $second = proposeTask($this->convId, ['title' => 'Same Title']);

    expect($second->status)->toBe(PendingActionStatus::Pending)
        ->and($second->getKey())->not->toBe($first->getKey())
        ->and(PendingAction::query()->where('conversation_id', $this->convId)->count())->toBe(2);
});
