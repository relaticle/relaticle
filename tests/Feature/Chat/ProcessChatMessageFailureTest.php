<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\PendingAction;

mutates(ProcessChatMessage::class);

function makeFailedTurnJob(User $user, string $conversationId): ProcessChatMessage
{
    return new ProcessChatMessage(
        user: $user,
        team: $user->currentTeam,
        message: 'Create a task titled BR-Foo',
        conversationId: $conversationId,
        resolved: ['provider' => 'ollama', 'model' => 'qwen3:8b'],
        mentions: [],
        document: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Create a task titled BR-Foo']]]]],
        turnId: (string) Str::ulid(),
    );
}

function seedFailedTurnMessage(string $conversationId, User $user, string $role, string $content, Carbon $createdAt): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'user_id' => (string) $user->getKey(),
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
        'user_id' => (string) $user->getKey(),
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
        'user_id' => (string) $user->getKey(),
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
        'user_id' => (string) $user->getKey(),
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
