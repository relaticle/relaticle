<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\TurnContinuationService;
use Relaticle\Chat\Support\TurnPresence;
use Tests\Helpers\ChatDocument;

mutates(TurnPresence::class, ChatInterface::class, TurnContinuationService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->team->getKey()], [
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

function turnPresenceSeedConversation(User $user): string
{
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

/** @param array<string, mixed> $overrides */
function turnPresenceSeedMessage(User $user, string $conversationId, array $overrides = []): void
{
    DB::table('agent_conversation_messages')->insert(array_merge([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'content' => 'earlier message',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now()->subMinutes(5),
        'updated_at' => now()->subMinutes(5),
    ], $overrides));
}

function turnPresenceSeedPendingAction(User $user, string $conversationId): string
{
    $pending = PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Follow up'],
        'display_data' => ['title' => 'Create Task', 'summary' => 'Create task "Follow up"'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    return (string) $pending->getKey();
}

it('marks the turn in flight when a message is sent', function (): void {
    Queue::fake();
    $conversationId = turnPresenceSeedConversation($this->user);

    $this->postJson(route('chat.send'), [
        'document' => ChatDocument::fromText('update categories for all contacts'),
        'conversation_id' => $conversationId,
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class);

    $presence = TurnPresence::current($conversationId);

    expect($presence)->not->toBeNull()
        ->and($presence['kind'])->toBe('message')
        ->and($presence['message'])->toBe('update categories for all contacts');
});

it('restores the in-flight user message and streaming flag on reload', function (): void {
    $conversationId = turnPresenceSeedConversation($this->user);
    turnPresenceSeedMessage($this->user, $conversationId);

    TurnPresence::begin($conversationId, turnId: 'turn-a', message: 'random');

    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->assertSet('turnInFlight', true);

    $messages = $component->get('messages');
    $last = end($messages);

    expect($last['role'])->toBe('user')
        ->and($last['content'])->toBe('random');
});

it('docks proposals whose carrying assistant message is not persisted yet', function (): void {
    $conversationId = turnPresenceSeedConversation($this->user);
    turnPresenceSeedMessage($this->user, $conversationId);
    $pendingId = turnPresenceSeedPendingAction($this->user, $conversationId);

    TurnPresence::begin($conversationId, turnId: 'turn-a', message: 'random');

    $messages = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->get('messages');

    $last = end($messages);

    expect($last['role'])->toBe('assistant')
        ->and($last['pending_actions'][0]['pending_action_id'])->toBe($pendingId)
        ->and($last['pending_actions'][0]['status'])->toBe('pending');
});

it('skips the injection once the turn is persisted but the marker lingers', function (): void {
    $conversationId = turnPresenceSeedConversation($this->user);

    TurnPresence::begin($conversationId, turnId: 'turn-a', message: 'random');

    turnPresenceSeedMessage($this->user, $conversationId, [
        'content' => 'random',
        'created_at' => now()->addSecond(),
        'updated_at' => now()->addSecond(),
    ]);

    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->assertSet('turnInFlight', false);

    expect($component->get('messages'))->toHaveCount(1);
});

it('shows a continuation turn without injecting a user bubble', function (): void {
    $conversationId = turnPresenceSeedConversation($this->user);
    turnPresenceSeedMessage($this->user, $conversationId, ['role' => 'assistant', 'content' => 'Created it.']);

    TurnPresence::begin($conversationId, turnId: 'turn-a', message: '', isContinuation: true);

    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->assertSet('turnInFlight', true);

    $messages = $component->get('messages');

    expect($messages)->toHaveCount(1)
        ->and($messages[0]['role'])->toBe('assistant');
});

it('clears the marker when the job fails for good', function (): void {
    $conversationId = turnPresenceSeedConversation($this->user);

    TurnPresence::begin($conversationId, turnId: 'turn-a', message: 'doomed');

    $job = new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'doomed',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'x', 'id' => 'x', 'source' => 'auto'],
        turnId: 'turn-a',
    );
    $job->failed(null);

    expect(TurnPresence::current($conversationId))->toBeNull();
});

it('keeps a newer turn marker when an older turn ends', function (): void {
    $conversationId = turnPresenceSeedConversation($this->user);

    TurnPresence::begin($conversationId, turnId: 'turn-b', message: 'newer send');

    TurnPresence::clear($conversationId, 'turn-a');

    expect(TurnPresence::current($conversationId)['message'] ?? null)->toBe('newer send');
});

it('anchors the dock server-side from the initial pending action id', function (): void {
    $conversationId = turnPresenceSeedConversation($this->user);
    $pendingId = turnPresenceSeedPendingAction($this->user, $conversationId);

    Livewire::test(ProposalCard::class, ['context' => 'conversation', 'initialPendingActionId' => $pendingId])
        ->assertSet('pendingActionId', $pendingId)
        ->assertSee('Follow up');
});

it('ignores an initial pending action id belonging to another user', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $conversationId = turnPresenceSeedConversation($other);
    $foreignId = turnPresenceSeedPendingAction($other, $conversationId);

    Livewire::test(ProposalCard::class, ['context' => 'conversation', 'initialPendingActionId' => $foreignId])
        ->assertSet('pendingActionId', null);
});

it('never leaks another team conversation in-flight turn on mount', function (): void {
    $victim = User::factory()->withPersonalTeam()->create();
    $conversationId = turnPresenceSeedConversation($victim);
    $pendingId = turnPresenceSeedPendingAction($victim, $conversationId);

    TurnPresence::begin($conversationId, turnId: 'turn-a', message: 'victim secret prompt');

    $component = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->assertSet('turnInFlight', false)
        ->assertSet('messages', [])
        ->assertDontSee($pendingId)
        ->assertDontSee('victim secret prompt');

    expect($component->get('messages'))->toBe([]);
});

it('never leaks a teammate conversation in-flight turn on mount', function (): void {
    $teammate = User::factory()->create();
    $this->team->users()->attach($teammate, ['role' => 'editor']);
    $teammate->forceFill(['current_team_id' => $this->team->getKey()])->save();

    $conversationId = turnPresenceSeedConversation($teammate);
    $pendingId = turnPresenceSeedPendingAction($teammate, $conversationId);

    TurnPresence::begin($conversationId, turnId: 'turn-a', message: 'teammate secret prompt');

    Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->assertSet('turnInFlight', false)
        ->assertSet('messages', [])
        ->assertDontSee($pendingId)
        ->assertDontSee('teammate secret prompt');
});

it('marks a continuation turn in flight when the assistant resumes', function (): void {
    Queue::fake();
    $conversationId = turnPresenceSeedConversation($this->user);

    $resumed = resolve(TurnContinuationService::class)
        ->resume($this->user, $conversationId, 'resolved-turn-a');

    expect($resumed)->toBeTrue();

    Queue::assertPushed(ProcessChatMessage::class);

    $presence = TurnPresence::current($conversationId);

    expect($presence)->not->toBeNull()
        ->and($presence['kind'])->toBe('continuation')
        ->and($presence['message'])->toBe('');
});

it('hands the dock the first pending card id at render', function (): void {
    $conversationId = turnPresenceSeedConversation($this->user);
    turnPresenceSeedMessage($this->user, $conversationId);
    $pendingId = turnPresenceSeedPendingAction($this->user, $conversationId);

    Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->assertViewHas('initialProposalId', $pendingId);
});

it('hands the dock no card id when nothing is pending', function (): void {
    $conversationId = turnPresenceSeedConversation($this->user);
    turnPresenceSeedMessage($this->user, $conversationId);

    Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->assertViewHas('initialProposalId', null);
});
