<?php

declare(strict_types=1);

use App\Actions\Onboarding\StartSetupGreeting;
use App\Actions\Task\CreateTask;
use App\Enums\Plan;
use App\Features\SetupConversation;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Events\ChatStreamRetrying;
use Relaticle\Chat\Exceptions\ProviderStreamRejectedException;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Queries\ConversationMessagesQuery;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\StoredSteps;
use Relaticle\Chat\Support\TurnPresence;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Tests\Helpers\AnthropicSse;

mutates(ProcessChatMessage::class);

/** @param array{provider: string|null, model: string|null, id: string|null, source: string} $resolved */
function makeFailedTurnJob(User $user, string $conversationId, array $resolved = ['provider' => 'ollama', 'model' => 'qwen3:8b', 'id' => 'ollama', 'source' => 'auto']): ProcessChatMessage
{
    return new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'Create a task titled BR-Foo',
        conversationId: $conversationId,
        resolved: $resolved,
        mentions: [],
        document: ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Create a task titled BR-Foo']]]]],
        turnId: (string) Str::ulid(),
    );
}

function seedFailedTurnMessage(string $conversationId, User $user, string $role, string $content, CarbonImmutable $createdAt): void
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
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'document' => json_encode(['type' => 'doc', 'content' => []], JSON_THROW_ON_ERROR),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('makes a failed turn coherent: user message, failure note, superseded proposal, one credit', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    // withPersonalWorkspace() already seeds a balance via WorkspaceCreated -> SeedWorkspaceCreditBalanceListener;
    // top it up rather than inserting a second row (would violate the workspace_id unique index).
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'BR failure',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A tool call created this mid-stream, then the turn died.
    DB::table('pending_actions')->insert([
        'id' => (string) Str::ulid(),
        'workspace_id' => $workspace->getKey(),
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
        ->and(AiCreditTransaction::query()->where('workspace_id', $workspace->getKey())->sum('credits_charged'))
        ->toBe(1);
});

it('leaves the thread empty when the opening turn dies, so the next open greets again', function (): void {
    Feature::define(SetupConversation::class, true);

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = $workspace->setupConversation->id;

    new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: '',
        conversationId: $conversationId,
        resolved: ['provider' => 'ollama', 'model' => 'qwen3:8b', 'id' => 'ollama', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
        origin: MessageOrigin::Greeting,
    )->failed(new RuntimeException('boom'));

    expect(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count())->toBe(0)
        ->and(resolve(ConversationMessagesQuery::class)->get($user, $conversationId))->toBe([]);

    Queue::fake();

    expect(resolve(StartSetupGreeting::class)->execute($user->fresh(), $conversationId))->toBeTrue();

    Queue::assertPushed(ProcessChatMessage::class);
});

it('records a dead resumed turn as its opener, never as words the user typed', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'dead resume',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: "The user decided the proposals above:\n- REJECTED (nothing was written): delete sample_data \"All sample records\"",
        conversationId: $conversationId,
        resolved: ['provider' => 'ollama', 'model' => 'qwen3:8b', 'id' => 'ollama', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
        origin: MessageOrigin::Resume,
        resumesTurnId: (string) Str::ulid(),
    );

    $job->failed(new RuntimeException('boom'));
    $job->failed(new RuntimeException('boom'));

    $userRows = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'user')
        ->get();

    expect($userRows)->toHaveCount(1)
        ->and($userRows->first()->origin)->toBe(MessageOrigin::Resume->value)
        ->and($userRows->first()->content)->toBe("The user decided the proposals above:\n- REJECTED (nothing was written): delete sample_data \"All sample records\"")
        ->and(array_column(resolve(ConversationMessagesQuery::class)->get($user, $conversationId), 'role'))
        ->toBe(['assistant']);
});

it('does not duplicate a completed turn or add an error note when a post-stream step fails', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'BR completed turn, post-stream step failed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The stream itself completed successfully -- both real rows already
    // exist -- but a post-stream step (settleReservation / maybeTitleFromTurn
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
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
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
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'BR timeout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = (string) Str::ulid();
    resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: $conversationId,
        userId: (string) $user->getKey(),
    );

    new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'Write the whole chapter',
        conversationId: $conversationId,
        resolved: ['provider' => 'ollama', 'model' => 'qwen3:8b', 'id' => 'ollama', 'source' => 'auto'],
        turnId: $turnId,
    )->failed(new TimeoutExceededException('timed out'));

    $note = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->value('content');

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();

    expect($note)->toBe('This reply hit the 120-second limit before it finished. Ask for a shorter answer, or for it in parts.')
        ->and($balance->credits_remaining)->toBe(100)
        ->and($balance->credits_used)->toBe(0);
});

it('orders the backfilled failed turn before a later retried turn when sorted by id', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
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
        ->and($messages[1]->content)->toContain('hit the 120-second limit')
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
        'workspace_id' => $user->currentWorkspace->getKey(),
        'title' => 'BR failover',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('redispatches once on a terminal pre-stream failure when resolution was auto', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    expect($credits->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: $conversationId,
        userId: (string) $user->getKey(),
    ))->toBeTrue();

    AnthropicSse::fake(AnthropicSse::TERMINAL_ERROR);
    Queue::fake();
    Event::fake([ChatStreamRetrying::class]);

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
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
    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();
    expect($balance->credits_used)->toBe(1)
        ->and($balance->credits_remaining)->toBe(99);
});

it('does not fail over for an explicit model pick', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    $credits->reserveCredit($workspace, reservationKey: "reserve-{$turnId}", conversationId: $conversationId, userId: (string) $user->getKey());

    AnthropicSse::fake(AnthropicSse::TERMINAL_ERROR);
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: $turnId,
    );

    expect(fn (): mixed => $job->handle($credits))->toThrow(RuntimeException::class);

    Queue::assertNothingPushed();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();
    expect($balance->credits_used)->toBe(1)
        ->and($balance->credits_remaining)->toBe(99);
});

it('names the picked model and offers Retry on Auto when an explicit pick fails', function (string $id, string $expectedNote): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalWorkspace()->create();
    $user->currentWorkspace->forceFill(['plan' => Plan::Pro])->save();
    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    makeFailedTurnJob($user, $conversationId, ['provider' => 'anthropic', 'model' => $id, 'id' => $id, 'source' => 'explicit'])
        ->failed(new InsufficientCreditsException('Your credit balance is too low to access the Anthropic API.'));

    $note = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->value('content');

    expect($note)->toBe($expectedNote);

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $event): bool => $event->broadcastWith() === [
        'conversationId' => $conversationId,
        'message' => $expectedNote,
        'retryOnAuto' => true,
    ]);
})->with([
    'a catalog model' => ['claude-sonnet-5', 'Sonnet 5 is unavailable right now. Retry on Auto to get an answer from another model.'],
    'a model the catalog no longer offers' => ['claude-sonnet-4-6', 'claude-sonnet-4-6 is unavailable right now. Retry on Auto to get an answer from another model.'],
]);

it('offers Retry on Auto when the provider rejects an explicit pick mid-stream', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalWorkspace()->create();
    $user->currentWorkspace->forceFill(['plan' => Plan::Pro])->save();
    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    makeFailedTurnJob($user, $conversationId, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'])
        ->failed(new ProviderStreamRejectedException(new Error('evt-1', 'invalid_request_error', 'bad request', false, 0)));

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $event): bool => $event->broadcastWith()['retryOnAuto'] === true
        && $event->broadcastWith()['message'] === 'Sonnet 5 is unavailable right now. Retry on Auto to get an answer from another model.');
});

it('offers no Retry on Auto when Auto has no other model to answer with', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    makeFailedTurnJob($user, $conversationId, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'])
        ->failed(new InsufficientCreditsException('Your credit balance is too low to access the Anthropic API.'));

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $event): bool => $event->retryOnAuto === false
        && ! str_contains($event->message, 'Retry on Auto'));
});

it('offers no Retry on Auto for a turn sent with an attachment, which has no retry button', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalWorkspace()->create();
    $user->currentWorkspace->forceFill(['plan' => Plan::Pro])->save();
    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: (string) Str::ulid(),
        attachment: ['id' => (string) Str::uuid(), 'name' => 'brief.md', 'kind' => 'text', 'row_count' => 0],
    );

    $job->failed(new InsufficientCreditsException('Your credit balance is too low to access the Anthropic API.'));

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $event): bool => $event->retryOnAuto === false
        && ! str_contains($event->message, 'Retry on Auto'));
});

it('offers no Retry on Auto for a greeting turn, which has no retry button', function (): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $user->currentWorkspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: (string) Str::ulid(),
        origin: MessageOrigin::Greeting,
    );

    $job->failed(new InsufficientCreditsException('Your credit balance is too low to access the Anthropic API.'));

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $event): bool => $event->retryOnAuto === false
        && ! str_contains($event->message, 'Retry on Auto'));
});

it('offers no Retry on Auto for an Auto turn, our own error, a timeout, or a rate limit', function (string $source, Throwable $exception): void {
    Event::fake([ChatStreamFailed::class]);

    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    makeFailedTurnJob($user, $conversationId, ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => $source])
        ->failed($exception);

    $note = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->value('content');

    expect($note)->not->toContain('Retry on Auto');

    Event::assertDispatched(ChatStreamFailed::class, fn (ChatStreamFailed $event): bool => $event->broadcastWith()['retryOnAuto'] === false);
})->with([
    'an Auto turn' => ['auto', new InsufficientCreditsException('Your credit balance is too low to access the Anthropic API.')],
    'an explicit pick failing in our own code' => ['explicit', new RuntimeException('boom')],
    'an explicit pick that timed out' => ['explicit', new TimeoutExceededException('timed out')],
    'an explicit pick that was rate-limited' => ['explicit', new RateLimitedException('rate limited', 429)],
]);

it('does not fail over once the stream has already broadcast an event', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    $credits->reserveCredit($workspace, reservationKey: "reserve-{$turnId}", conversationId: $conversationId, userId: (string) $user->getKey());

    AnthropicSse::fake(AnthropicSse::STREAM_STARTED_THEN_ERROR);
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: $turnId,
    );

    expect(fn (): mixed => $job->handle($credits))->toThrow(RuntimeException::class);

    Queue::assertNothingPushed();
});

it('keeps one user message and the failure note when the turn dies after a completed tool step', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    $credits->reserveCredit($workspace, reservationKey: "reserve-{$turnId}", conversationId: $conversationId, userId: (string) $user->getKey());

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(AnthropicSse::toolUseStep('GetCrmSummaryTool'), 200, ['Content-Type' => 'text/event-stream'])
            ->push('upstream exploded', 500),
    ]);
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'How is my pipeline?',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: $turnId,
    );

    try {
        $job->handle($credits);
    } catch (Throwable $exception) {
        $job->failed($exception);
    }

    $rows = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->orderBy('id')
        ->get(['role', 'content', 'status']);

    expect($rows->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($rows[0]->content)->toBe('How is my pipeline?')
        ->and($rows[1]->content)->toBe('Sonnet 5 is unavailable right now. Retry on Auto to get an answer from another model.')
        ->and($rows->pluck('status')->unique()->all())->toBe(['completed']);
});

it('replays the failure note to the model on the next turn', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    makeFailedTurnJob($user, $conversationId)->failed(new RuntimeException('boom'));

    $history = resolve(ConversationStore::class)->getLatestConversationMessages($conversationId, 100);

    expect($history->last()->role->value)->toBe('assistant')
        ->and($history->last()->content)->toBe(__('The assistant encountered an error. Please try again.'));
});

it('keeps one user message when a turn retried after a transient failure mid-turn succeeds', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    $credits->reserveCredit($workspace, reservationKey: "reserve-{$turnId}", conversationId: $conversationId, userId: (string) $user->getKey());

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(AnthropicSse::toolUseStep('GetCrmSummaryTool'), 200, ['Content-Type' => 'text/event-stream'])
            ->push('overloaded', 529)
            ->push(AnthropicSse::reply('Your pipeline is healthy.', 'claude-sonnet-5'), 200, ['Content-Type' => 'text/event-stream']),
    ]);
    Queue::fake();
    Event::fake([ChatStreamRetrying::class]);

    $job = fn (): ProcessChatMessage => new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'How is my pipeline?',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: $turnId,
    );

    $job()->handle($credits);

    Event::assertDispatched(ChatStreamRetrying::class);

    $job()->handle($credits);

    $rows = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->orderBy('id')
        ->get(['role', 'content']);

    expect($rows->pluck('role')->all())->toBe(['user', 'assistant'])
        ->and($rows[1]->content)->toBe('Your pipeline is healthy.');
});

it('lets the model recover from calling a tool that does not exist instead of failing the turn', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    $credits->reserveCredit($workspace, reservationKey: "reserve-{$turnId}", conversationId: $conversationId, userId: (string) $user->getKey());

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(AnthropicSse::toolUseStep('ListDealsTool'), 200, ['Content-Type' => 'text/event-stream'])
            ->push(AnthropicSse::reply('Deals live under opportunities.', 'claude-sonnet-5'), 200, ['Content-Type' => 'text/event-stream']),
    ]);
    Queue::fake();

    (new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'List my deals',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: $turnId,
    ))->handle($credits);

    $assistant = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->sole();

    $repairedCall = json_decode((string) $assistant->steps, true)[0]['tool_calls'][0];

    expect($assistant->content)->toBe('Deals live under opportunities.')
        ->and($repairedCall['name'])->toBe('ListDealsTool')
        ->and($repairedCall['failed'])->toBeTrue()
        ->and($repairedCall['result'])->toStartWith("Tool 'ListDealsTool' does not exist. Available tools: ");
});

it('refunds the reservation when the job dies before it ever runs', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: $conversationId,
        userId: (string) $user->getKey(),
    );

    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('credits_remaining'))->toBe(99);

    // A queue backlog past retryUntil() fails the job at pickup, so handle() never
    // runs and nothing streamed. The user must not pay for a turn that never
    // reached a provider.
    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: $turnId,
    );

    $job->failed(new MaxAttemptsExceededException('job expired'));

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();

    expect($balance->credits_remaining)->toBe(100)
        ->and($balance->credits_used)->toBe(0);
});

it('reports a pre-model failure to the exception handler instead of only a breadcrumb', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    // Break agent construction the way a bad deploy would.
    app()->bind(CrmAssistant::class, function (): never {
        throw new RuntimeException('agent construction exploded');
    });

    Exceptions::fake();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
    );

    $job->handle(resolve(CreditService::class));

    Exceptions::assertReported(RuntimeException::class);
});

it('logs the provider error type and message when the provider rejects an explicit pick', function (string $errorMessage): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => $errorMessage],
        ], 400),
    ]);
    Queue::fake();
    Log::spy();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: (string) Str::ulid(),
    );

    expect(fn (): mixed => $job->handle(resolve(CreditService::class)))->toThrow(Exception::class);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'Chat provider rejected the turn'
            && $context['model'] === 'claude-sonnet-5'
            && $context['status'] === 400
            && $context['error_type'] === 'invalid_request_error'
            && $context['error_message'] === $errorMessage);
})->with([
    'an invalid request' => ['model: claude-sonnet-5 is not available'],
    'a credit balance too low' => ['Your credit balance is too low to access the Anthropic API.'],
]);

it('logs the provider rejection before an auto pick fails over', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'invalid_request_error', 'message' => 'model: claude-sonnet-5 is not available'],
        ], 400),
    ]);
    Queue::fake();
    Log::spy();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
    );

    $job->handle(resolve(CreditService::class));

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $pushed): bool => $pushed->failoverDepth === 1);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'Chat provider rejected the turn'
            && $context['error_type'] === 'invalid_request_error');
});

it('logs an overloaded provider once its retries are spent', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    AnthropicSse::fake("data: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}}\n\n");
    Queue::fake();
    Log::spy();

    $job = (new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: (string) Str::ulid(),
    ))->withFakeQueueInteractions();
    $job->job->attempts = 5;

    expect(fn (): mixed => $job->handle(resolve(CreditService::class)))->toThrow(Exception::class);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'Chat provider rejected the turn'
            && $context['model'] === 'claude-sonnet-5'
            && $context['error_type'] === 'ProviderOverloadedException'
            && str_contains((string) $context['error_message'], 'overloaded_error'));
});

it('still fails over and logs when the provider error type is not a string', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => ['type' => 400, 'message' => 'bad request']], 400),
    ]);
    Queue::fake();
    Log::spy();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
    );

    $job->handle(resolve(CreditService::class));

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $pushed): bool => $pushed->failoverDepth === 1);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'Chat provider rejected the turn'
            && $context['status'] === 400
            && $context['error_type'] === null
            && $context['error_message'] === 'bad request');
});

it('logs the provider error type and message when the provider reports a rejection in the stream', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    AnthropicSse::fake(AnthropicSse::STREAM_STARTED_THEN_ERROR);
    Queue::fake();
    Log::spy();

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'hello',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: (string) Str::ulid(),
    );

    expect(fn (): mixed => $job->handle(resolve(CreditService::class)))->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context = []): bool => $message === 'Chat provider rejected the turn'
            && $context['model'] === 'claude-sonnet-5'
            && $context['error_type'] === 'invalid_request_error'
            && $context['error_message'] === 'bad request');
});

/**
 * @param  array<string, mixed>  $input
 * @return array{user: User, conversationId: string, turnId: string, job: Closure(?string): ProcessChatMessage, credits: CreditService}
 */
function retriedProposalTurn(string $tool, array $input, int $extraTurns = 0): array
{
    config()->set('relaticle.features.email_integration', true);
    config()->set('chat.provider_starts_per_second', 1000);
    Feature::flushCache();

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->update(['credits_remaining' => 100, 'credits_used' => 0]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
    ]));

    $conversationId = (string) Str::uuid7();
    seedFailoverConversation($user, $conversationId);

    $turnId = (string) Str::ulid();
    $credits = resolve(CreditService::class);
    $credits->reserveCredit($workspace, reservationKey: "reserve-{$turnId}", conversationId: $conversationId, userId: (string) $user->getKey());

    $input = json_decode(str_replace('{account}', (string) $account->getKey(), (string) json_encode($input)), true);

    $sequence = Http::sequence()
        ->push(AnthropicSse::toolUseStepWithInput($tool, $input), 200, ['Content-Type' => 'text/event-stream'])
        ->push('overloaded', 529)
        ->push(AnthropicSse::toolUseStepWithInput($tool, $input), 200, ['Content-Type' => 'text/event-stream'])
        ->push(AnthropicSse::reply('Review the proposal below.', 'claude-sonnet-5'), 200, ['Content-Type' => 'text/event-stream']);

    for ($extra = 0; $extra < $extraTurns; $extra++) {
        $sequence->push(AnthropicSse::toolUseStepWithInput($tool, $input), 200, ['Content-Type' => 'text/event-stream'])
            ->push(AnthropicSse::reply('Review the proposal below.', 'claude-sonnet-5'), 200, ['Content-Type' => 'text/event-stream']);
    }

    Http::fake(['api.anthropic.com/*' => $sequence]);
    Queue::fake();
    Event::fake([ChatStreamRetrying::class]);

    $job = fn (?string $turn = null): ProcessChatMessage => new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'Do it',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: $turn ?? $turnId,
    );

    test()->actingAs($user);
    Filament::setTenant($workspace);

    return ['user' => $user, 'conversationId' => $conversationId, 'turnId' => $turnId, 'job' => $job, 'credits' => $credits];
}

/** @return list<array<string, mixed>> */
function decideRetriedProposal(array $turn, string $decision): void
{
    test()->actingAs($turn['user']);
    Filament::setTenant($turn['user']->currentWorkspace);

    $pending = PendingAction::query()->where('conversation_id', $turn['conversationId'])->sole();

    $decision === 'approve'
        ? resolve(PendingActionService::class)->approve($pending, $turn['user'])
        : resolve(PendingActionService::class)->reject($pending, $turn['user']);
}

function storedProposalEnvelopes(string $conversationId): array
{
    $steps = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->where('role', 'assistant')->pluck('steps');

    return collect($steps)
        ->flatMap(fn (string $json): array => StoredSteps::toolResults($json))
        ->map(fn (array $result): mixed => json_decode((string) $result['result'], true))
        ->filter(fn (mixed $envelope): bool => is_array($envelope) && ($envelope['type'] ?? null) === 'pending_action')
        ->values()
        ->all();
}

$sendInput = ['records' => [['connected_account_id' => '{account}', 'to' => ['lena@acme.test'], 'subject' => 'Q4 lanes', 'body' => 'Confirmed.']]];

describe('a turn retried after its proposal was decided', function () use ($sendInput): void {
    it('answers an approved send with that card, decided, and queues one email', function () use ($sendInput): void {
        $turn = retriedProposalTurn('SendEmailTool', $sendInput);

        $turn['job']()->handle($turn['credits']);

        $first = PendingAction::query()->where('conversation_id', $turn['conversationId'])->sole();
        decideRetriedProposal($turn, 'approve');

        $turn['job']()->handle($turn['credits']);

        $envelope = storedProposalEnvelopes($turn['conversationId'])[0];

        expect(PendingAction::query()->where('conversation_id', $turn['conversationId'])->get()->pluck('status')->all())->toBe([PendingActionStatus::Approved])
            ->and(Email::query()->count())->toBe(1)
            ->and($envelope['pending_action_id'])->toBe($first->getKey())
            ->and($envelope['status'])->toBe('approved')
            ->and($envelope['meta'])->toBe(['agent_should_stop' => false])
            ->and($envelope['outcome']['id'])->toBe(Email::query()->sole()->getKey())
            ->and($envelope['message'])->toContain('already decided')->toContain('approved');
    });

    it('marks its turn as retried when it releases for a transient error', function () use ($sendInput): void {
        $turn = retriedProposalTurn('SendEmailTool', $sendInput);
        TurnPresence::begin($turn['conversationId'], turnId: $turn['turnId'], message: 'Do it');

        expect(TurnPresence::current($turn['conversationId'])['retried'] ?? false)->toBeFalse();

        $turn['job']()->handle($turn['credits']);

        expect(TurnPresence::current($turn['conversationId'])['retried'])->toBeTrue();
    });

    it('answers a rejected send with that card, decided, and sends nothing', function () use ($sendInput): void {
        $turn = retriedProposalTurn('SendEmailTool', $sendInput);

        $turn['job']()->handle($turn['credits']);

        $first = PendingAction::query()->where('conversation_id', $turn['conversationId'])->sole();
        decideRetriedProposal($turn, 'reject');

        $turn['job']()->handle($turn['credits']);

        $envelope = storedProposalEnvelopes($turn['conversationId'])[0];

        expect(PendingAction::query()->where('conversation_id', $turn['conversationId'])->get()->pluck('status')->all())->toBe([PendingActionStatus::Rejected])
            ->and(Email::query()->count())->toBe(0)
            ->and($envelope['status'])->toBe('rejected')
            ->and($envelope['meta'])->toBe(['agent_should_stop' => false])
            ->and($envelope['message'])->toContain('rejected');
    });

    it('answers an approved task with that card, so one task exists and not two', function (): void {
        $turn = retriedProposalTurn('CreateTaskTool', ['records' => [['title' => 'Call Lena']]]);

        $turn['job']()->handle($turn['credits']);

        $first = PendingAction::query()->where('conversation_id', $turn['conversationId'])->sole();
        decideRetriedProposal($turn, 'approve');

        $turn['job']()->handle($turn['credits']);

        expect(PendingAction::query()->where('conversation_id', $turn['conversationId'])->count())->toBe(1)
            ->and(storedProposalEnvelopes($turn['conversationId'])[0]['status'])->toBe('approved')
            ->and(Task::query()->where('title', 'Call Lena')->count())->toBe(1);
    });

    it('shows one decided card after a reload, never a pending one', function () use ($sendInput): void {
        $turn = retriedProposalTurn('SendEmailTool', $sendInput);

        $turn['job']()->handle($turn['credits']);
        decideRetriedProposal($turn, 'approve');
        $turn['job']()->handle($turn['credits']);

        $messages = resolve(ConversationMessagesQuery::class)->get($turn['user'], $turn['conversationId']);
        $cards = collect($messages)->flatMap(fn (array $message): array => $message['pending_actions']);

        expect($cards)->toHaveCount(1)
            ->and($cards[0]['status'])->toBe('approved');
    });

    it('gives a superseded identical proposal a new pending card', function () use ($sendInput): void {
        $turn = retriedProposalTurn('SendEmailTool', $sendInput);

        $turn['job']()->handle($turn['credits']);
        $turn['job']()->handle($turn['credits']);

        $rows = PendingAction::query()->where('conversation_id', $turn['conversationId'])->orderBy('id')->get();

        expect($rows->pluck('status')->all())->toBe([PendingActionStatus::Superseded, PendingActionStatus::Pending]);
    });

    it('gives the same proposal under a different turn a new pending card', function () use ($sendInput): void {
        $turn = retriedProposalTurn('SendEmailTool', $sendInput);

        $turn['job']()->handle($turn['credits']);
        decideRetriedProposal($turn, 'approve');

        $otherTurn = (string) Str::ulid();
        $turn['credits']->reserveCredit($turn['user']->currentWorkspace, reservationKey: "reserve-{$otherTurn}", conversationId: $turn['conversationId'], userId: (string) $turn['user']->getKey());
        $turn['job']($otherTurn)->handle($turn['credits']);

        $rows = PendingAction::query()->where('conversation_id', $turn['conversationId'])->orderBy('id')->get();

        expect($rows->pluck('status')->first())->toBe(PendingActionStatus::Approved)
            ->and($rows->last()->status)->toBe(PendingActionStatus::Pending)
            ->and($rows->last()->turn_id)->toBe($otherTurn);
    });
});
