<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Contracts\ConversationStore;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Tests\Helpers\AnthropicSse;

it('refunds the reservation when a job fails without ever streaming', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-1',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    resolve(CreditService::class)->reserveCredit($workspace); // used 1

    $job = new ProcessChatMessage(
        user: $user, workspace: $workspace, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNFAILAAAAAAAAAAAAAAAA',
    );
    $job->failed(new RuntimeException('timeout'));

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();

    // Nothing streamed, so no provider was ever called and the turn cost nothing.
    expect($balance->credits_used)->toBe(0)
        ->and($balance->credits_remaining)->toBe(100);
});

it('settles the reserved minimum when the turn already streamed before failing', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-2',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNSTREAMEDAAAAAAAAAAAA';
    expect(resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-2',
        userId: (string) $user->getKey(),
    ))->toBeTrue();

    // A real turn that reaches the provider, emits, and then dies. Setting the
    // private flag by reflection instead would manufacture the exact state under
    // test: nothing would then prove a live stream ever sets it, and a refactor
    // that stopped setting it would silently refund every turn the provider had
    // already billed us for, with this test still green.
    AnthropicSse::fake(AnthropicSse::streamedThenError('Working on it'));
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user, workspace: $workspace, message: 'hi', conversationId: 'c-2',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        turnId: $turnId,
    );

    try {
        $job->handle(resolve(CreditService::class));
    } catch (Throwable) {
        // The turn dying mid-stream is the premise; what it is billed is the subject.
    }

    $job->failed(new RuntimeException('died mid-stream'));

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();
    expect($balance->credits_used)->toBe(1)
        ->and($balance->credits_remaining)->toBe(99);
});

/**
 * The production path, not the in-memory one. The queue never calls failed() on
 * the object that ran handle(): CallQueuedHandler::failed() rebuilds the command
 * from the ORIGINAL dispatch payload (getCommand($data)), so every private flag
 * is back at its dispatch-time value. A test that keeps the same instance across
 * handle() and failed() therefore asserts a state production never reaches.
 */
it('bills a turn that streamed even though the queue hands failed() a fresh instance', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-3',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNFRESHINSTANCEAAAAAAA';
    expect(resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-3',
        userId: (string) $user->getKey(),
    ))->toBeTrue();

    AnthropicSse::fake(AnthropicSse::streamedThenError('Working on it'));
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user, workspace: $workspace, message: 'hi', conversationId: 'c-3',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        turnId: $turnId,
    );

    // What the worker actually holds: the payload as it was queued.
    $queued = serialize($job);

    try {
        $job->handle(resolve(CreditService::class));
    } catch (Throwable) {
        // Premise.
    }

    // What CallQueuedHandler::failed() actually calls it on.
    $fromPayload = unserialize($queued);
    $fromPayload->failed(new RuntimeException('died mid-stream'));

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();

    expect($balance->credits_used)->toBe(1, 'a turn the provider already billed was refunded')
        ->and($balance->credits_remaining)->toBe(99);
});

it('settles a completed turn on the model it requested and the uncached input tokens', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-4',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNSETTLEDAAAAAAAAAAAAA';
    resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-4',
        userId: (string) $user->getKey(),
    );

    AnthropicSse::fake(AnthropicSse::reply('Your pipeline holds three deals.', 'claude-opus-5-20260301'));
    Queue::fake();

    (new ProcessChatMessage(
        user: $user, workspace: $workspace, message: 'How is my pipeline?', conversationId: 'c-4',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'id' => 'claude-opus-5', 'source' => 'explicit'],
        turnId: $turnId,
    ))->handle(resolve(CreditService::class));

    $settlement = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('model', '!=', 'system')
        ->sole();

    expect($settlement->model)->toBe('claude-opus-5')
        ->and($settlement->credits_charged)->toBe(3)
        ->and($settlement->input_tokens)->toBe(40)
        ->and($settlement->output_tokens)->toBe(12);
});

it('records a cancelled turn on the model it streamed and still charges one credit', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-6',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNCANCELLEDAAAAAAAAAAA';
    resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-6',
        userId: (string) $user->getKey(),
    );

    AnthropicSse::fake(AnthropicSse::reply('Three deals.', 'claude-opus-5-20260301'));
    Queue::fake();
    Cache::put('chat:cancel:c-6', true);

    (new ProcessChatMessage(
        user: $user, workspace: $workspace, message: 'How is my pipeline?', conversationId: 'c-6',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'id' => 'claude-opus-5', 'source' => 'explicit'],
        turnId: $turnId,
    ))->handle(resolve(CreditService::class));

    $settlement = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('idempotency_key', "resolve-{$turnId}")
        ->sole();

    expect($settlement->model)->toBe('claude-opus-5')
        ->and($settlement->credits_charged)->toBe(1)
        ->and($settlement->input_tokens)->toBe(40)
        ->and($settlement->output_tokens)->toBe(12)
        ->and($settlement->metadata['reason'])->toBe('cancelled');
});

it('records a turn that died from a provider error as incomplete', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-7',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNERRORAAAAAAAAAAAAAAA';
    resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-7',
        userId: (string) $user->getKey(),
    );

    AnthropicSse::fake(AnthropicSse::streamedThenError('Partial answer'));
    Queue::fake();

    expect(fn () => (new ProcessChatMessage(
        user: $user, workspace: $workspace, message: 'How is my pipeline?', conversationId: 'c-7',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-opus-5', 'id' => 'claude-opus-5', 'source' => 'explicit'],
        turnId: $turnId,
    ))->handle(resolve(CreditService::class)))->toThrow(RuntimeException::class);

    $settlement = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('idempotency_key', "resolve-{$turnId}")
        ->sole();

    expect($settlement->model)->toBe('incomplete');
});

it('refunds a turn that ends with no text and no tool call and stores an acknowledgement', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-8',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNBLANKAAAAAAAAAAAAAAA';
    resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-8',
        userId: (string) $user->getKey(),
    );

    AnthropicSse::fake(AnthropicSse::reply('', 'claude-sonnet-5'));
    Queue::fake();

    (new ProcessChatMessage(
        user: $user, workspace: $workspace, message: 'Here is part one of my notes.', conversationId: 'c-8',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: $turnId,
    ))->handle(resolve(CreditService::class));

    $acknowledgement = __('Noted. Go on, or ask me a question.');

    $resolution = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('idempotency_key', "resolve-{$turnId}")
        ->sole();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();

    $assistant = DB::table('agent_conversation_messages')
        ->where('conversation_id', 'c-8')
        ->where('role', 'assistant')
        ->sole();

    expect($resolution->type)->toBe(AiCreditType::Refund)
        ->and($balance->credits_remaining)->toBe(100)
        ->and($balance->credits_used)->toBe(0)
        ->and($assistant->content)->toBe($acknowledgement)
        ->and(json_decode((string) $assistant->steps, true)[0]['content'])->toBe($acknowledgement)
        ->and(json_decode((string) $assistant->document, true)['content'][0]['content'][0]['text'])->toBe($acknowledgement)
        ->and(resolve(ConversationStore::class)->getLatestConversationMessages('c-8', 100)->last()->content)->toBe($acknowledgement);
});

it('still bills a turn that called a tool and wrote no text', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-9',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNTOOLONLYAAAAAAAAAAAA';
    resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-9',
        userId: (string) $user->getKey(),
    );

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(AnthropicSse::toolUseStep('GetCrmSummaryTool'), 200, ['Content-Type' => 'text/event-stream'])
            ->push(AnthropicSse::reply('', 'claude-sonnet-5'), 200, ['Content-Type' => 'text/event-stream']),
    ]);
    Queue::fake();

    (new ProcessChatMessage(
        user: $user, workspace: $workspace, message: 'How is my pipeline?', conversationId: 'c-9',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: $turnId,
    ))->handle(resolve(CreditService::class));

    $resolution = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('idempotency_key', "resolve-{$turnId}")
        ->sole();

    expect($resolution->type)->toBe(AiCreditType::Chat)
        ->and($resolution->credits_charged)->toBe(2)
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', 'c-9')->where('role', 'assistant')->value('content'))->toBe('');
});

it('refunds a blank turn and stores copy chosen by how the model stopped', function (string $reply, string $stopReason, string $expected): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-10',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNBLANKREASONAAAAAAAAA';
    resolve(CreditService::class)->reserveCredit(
        $workspace,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-10',
        userId: (string) $user->getKey(),
    );

    AnthropicSse::fake(AnthropicSse::reply($reply, 'claude-sonnet-5', $stopReason));
    Queue::fake();

    (new ProcessChatMessage(
        user: $user, workspace: $workspace, message: 'Summarise everything.', conversationId: 'c-10',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'explicit'],
        turnId: $turnId,
    ))->handle(resolve(CreditService::class));

    $resolution = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('idempotency_key', "resolve-{$turnId}")
        ->sole();

    $assistant = DB::table('agent_conversation_messages')
        ->where('conversation_id', 'c-10')
        ->where('role', 'assistant')
        ->sole();

    expect($resolution->type)->toBe(AiCreditType::Refund)
        ->and($assistant->content)->toBe($expected)
        ->and(json_decode((string) $assistant->steps, true)[0]['content'])->toBe($expected)
        ->and(json_decode((string) $assistant->document, true)['content'][0]['content'][0]['text'])->toBe($expected);
})->with([
    'whitespace-only reply' => ["\n", 'end_turn', 'Noted. Go on, or ask me a question.'],
    'ran out of room' => ['', 'max_tokens', 'This reply ran out of room before it said anything. Ask again, or ask for a shorter answer.'],
    'declined by the model' => ['', 'refusal', 'The model declined to answer this. Try rephrasing your request.'],
    'unrecognised stop reason' => ['', 'something_new', 'Noted. Go on, or ask me a question.'],
]);
