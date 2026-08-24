<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Enums\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Events\ChatStreamRetrying;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\CreditService;

mutates(ProcessChatMessage::class);

function makeFailedTurnJob(User $user, string $conversationId): ProcessChatMessage
{
    return new ProcessChatMessage(
        user: $user,
        team: $user->currentTeam,
        message: 'Create a task titled BR-Foo',
        conversationId: $conversationId,
        resolved: ['provider' => 'ollama', 'model' => 'qwen3:8b', 'id' => 'ollama', 'source' => 'auto'],
        mentions: [],
        document: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Create a task titled BR-Foo']]]]],
        turnId: (string) Str::ulid(),
    );
}

function seedFailedTurnMessage(string $conversationId, User $user, string $role, string $content, Carbon $createdAt): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => CrmAssistant::class,
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'document' => json_encode(['type' => 'doc', 'content' => []], JSON_THROW_ON_ERROR),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('makes a failed turn coherent: user message, failure note, superseded proposal, one credit', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    // withPersonalTeam() already seeds a balance via TeamCreated -> SeedTeamCreditBalanceListener;
    // top it up rather than inserting a second row (would violate the team_id unique index).
    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'BR failure',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A tool call created this mid-stream, then the turn died.
    DB::table('pending_actions')->insert([
        'id' => (string) Str::ulid(),
        'team_id' => $team->getKey(),
        'user_id' => (string) $user->getKey(),
        'conversation_id' => $conversationId,
        'action_class' => CreateTask::class,
        'operation' => 'create',
        'entity_type' => 'task',
        'action_data' => json_encode(['title' => 'BR-Foo']),
        'display_data' => json_encode(['title' => 'Create Task', 'summary' => 'Create task "BR-Foo"']),
        'status' => PendingActionStatus::Pending->value,
        'expires_at' => now()->addMinutes(15),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    makeFailedTurnJob($user, $conversationId)->failed(new RuntimeException('boom'));

    $messages = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId);

    expect($messages->clone()->where('role', 'user')->where('content', 'Create a task titled BR-Foo')->exists())->toBeTrue()
        ->and($messages->clone()->where('role', 'assistant')->exists())->toBeTrue()
        ->and(PendingAction::query()->where('conversation_id', $conversationId)->value('status'))
        ->toBe(PendingActionStatus::Superseded)
        ->and(AiCreditTransaction::query()->where('team_id', $team->getKey())->sum('credits_charged'))
        ->toBe(1);
});

it('does not duplicate a completed turn or add an error note when a post-stream step fails', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'BR completed turn, post-stream step failed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The stream itself completed successfully -- both real rows already
    // exist -- but a post-stream step (settleReservation / broadcastFollowUps
    // / ...) threw afterward, so the job still fails.
    seedFailedTurnMessage($conversationId, $user, 'user', 'Create a task titled BR-Foo', now()->subSecond());
    seedFailedTurnMessage($conversationId, $user, 'assistant', 'Done, I created the task.', now());

    makeFailedTurnJob($user, $conversationId)->failed(new RuntimeException('post-process boom'));

    $messages = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->get();

    expect($messages)->toHaveCount(2)
        ->and($messages->where('role', 'assistant')->contains(
            fn (object $message): bool => str_contains((string) $message->content, 'encountered an error'),
        ))->toBeFalse();
});

it('backfills a newly failed turn even when a prior completed turn exists', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'BR backfill after unrelated completed turn',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    seedFailedTurnMessage($conversationId, $user, 'user', 'old question', now()->subMinutes(2));
    seedFailedTurnMessage($conversationId, $user, 'assistant', 'old reply', now()->subMinutes(2)->addSecond());

    makeFailedTurnJob($user, $conversationId)->failed(new RuntimeException('boom'));

    $messages = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->get();

    expect($messages)->toHaveCount(4)
        ->and($messages->where('role', 'user')->where('content', 'Create a task titled BR-Foo')->count())->toBe(1)
        ->and($messages->where('role', 'assistant')->contains(
            fn (object $message): bool => str_contains((string) $message->content, 'encountered an error'),
        ))->toBeTrue();
});

it('shows timeout-specific copy when the turn times out', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'BR timeout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    makeFailedTurnJob($user, $conversationId)->failed(new TimeoutExceededException('timed out'));

    $note = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->value('content');

    expect($note)->toContain('respond within the time limit');
});

it('orders the backfilled failed turn before a later retried turn when sorted by id', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'BR failed turn then retry ordering',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Turn 1 dies mid-stream; failed() backfills [user, assistant-note].
    makeFailedTurnJob($user, $conversationId)->failed(new TimeoutExceededException('timed out'));

    // Turn 2 is the user's retry, persisted the way the real ConversationStore
    // does it (uuid7 ids), generated strictly after the failed() call above.
    seedFailedTurnMessage($conversationId, $user, 'user', 'Retry: create a task titled BR-Foo', now());
    seedFailedTurnMessage($conversationId, $user, 'assistant', 'Done, I created the task.', now());

    $messages = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->orderBy('id')
        ->get(['role', 'content']);

    expect($messages)->toHaveCount(4);

    // True chronological order: the failed turn happened first, the retry
    // happened second. If the backfilled rows used ULIDs (string-sorting
    // after a current-era uuid7), they would jump to the end instead.
    expect($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('Create a task titled BR-Foo')
        ->and($messages[1]->role)->toBe('assistant')
        ->and($messages[1]->content)->toContain('respond within the time limit')
        ->and($messages[2]->role)->toBe('user')
        ->and($messages[2]->content)->toBe('Retry: create a task titled BR-Foo')
        ->and($messages[3]->role)->toBe('assistant')
        ->and($messages[3]->content)->toBe('Done, I created the task.');
});

function seedFailoverConversation(User $user, string $conversationId): void
{
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'BR failover',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Fake the raw Anthropic SSE transport so the real streaming pipeline (agent,
 * gateway, ProcessChatMessage::handle()) runs for real and only the network
 * response is canned. `data:` lines are all `parseServerSentEvents()` reads;
 * `event:` lines are ignored by the parser, so they are omitted here.
 */
function fakeAnthropicSse(string $body): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream']),
    ]);
}

const SSE_TERMINAL_ERROR = "data: {\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"message\":\"bad request\"}}\n\n";

const SSE_STREAM_STARTED_THEN_ERROR = "data: {\"type\":\"message_start\",\"message\":{\"model\":\"claude-sonnet-4-6\",\"usage\":{\"input_tokens\":5}}}\n\ndata: {\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"message\":\"bad request\"}}\n\n";

it('redispatches once on a terminal pre-stream failure when resolution was auto', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    expect($credits->reserveCredit(
        $team,
        reservationKey: "reserve-{$turnId}",
        conversationId: $conversationId,
        userId: (string) $user->getKey(),
    ))->toBeTrue();

    fakeAnthropicSse(SSE_TERMINAL_ERROR);
    Queue::fake();
    Event::fake([ChatStreamRetrying::class]);

    $job = new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
        turnId: $turnId,
    );

    $job->handle($credits);

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $pushed): bool => $pushed->failoverDepth === 1
        && $pushed->conversationId === $conversationId
        && $pushed->turnId === $turnId);

    // The swap itself stays silent (the user never picked this model), but the
    // client is told the turn is still alive so it re-arms its stream watchdog
    // instead of sitting on "Thinking..." until it gives up.
    Event::assertDispatched(fn (ChatStreamRetrying $event): bool => $event->conversationId === $conversationId
        && $event->delaySeconds === 0);

    // The reservation made before dispatch is untouched by this failed attempt:
    // not refunded (the turn is still in flight on the re-dispatched job) and
    // not double-charged (only one attempt will ever settle resolutionKey
    // "resolve-{$turnId}", which both attempts share).
    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_used)->toBe(1)
        ->and($balance->credits_remaining)->toBe(99);
});

it('does not fail over for an explicit model pick', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    $credits->reserveCredit($team, reservationKey: "reserve-{$turnId}", conversationId: $conversationId, userId: (string) $user->getKey());

    fakeAnthropicSse(SSE_TERMINAL_ERROR);
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'explicit'],
        turnId: $turnId,
    );

    expect(fn (): mixed => $job->handle($credits))->toThrow(RuntimeException::class);

    Queue::assertNothingPushed();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_used)->toBe(1)
        ->and($balance->credits_remaining)->toBe(99);
});

it('does not fail over once the stream has already broadcast an event', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    $credits->reserveCredit($team, reservationKey: "reserve-{$turnId}", conversationId: $conversationId, userId: (string) $user->getKey());

    fakeAnthropicSse(SSE_STREAM_STARTED_THEN_ERROR);
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
        turnId: $turnId,
    );

    expect(fn (): mixed => $job->handle($credits))->toThrow(RuntimeException::class);

    Queue::assertNothingPushed();
});

it('refunds the reservation when the job dies before it ever runs', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    resolve(CreditService::class)->reserveCredit(
        $team,
        reservationKey: "reserve-{$turnId}",
        conversationId: $conversationId,
        userId: (string) $user->getKey(),
    );

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->value('credits_remaining'))->toBe(99);

    // A queue backlog past retryUntil() fails the job at pickup, so handle() never
    // runs and nothing streamed. The user must not pay for a turn that never
    // reached a provider.
    $job = new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
        turnId: $turnId,
    );

    $job->failed(new MaxAttemptsExceededException('job expired'));

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();

    expect($balance->credits_remaining)->toBe(100)
        ->and($balance->credits_used)->toBe(0);
});
