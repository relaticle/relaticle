<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Commands\ChatHealthCommand;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Queries\ChatHealthQuery;
use Relaticle\Chat\Support\TurnNotes;
use Tests\Helpers\ChatDocument;

mutates(ChatHealthQuery::class, ChatHealthCommand::class);

function chatHealthConversation(User $user, ?string $purpose = null, bool $withoutWorkspace = false): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $withoutWorkspace ? null : $user->currentWorkspace->getKey(),
        'title' => 'T',
        'purpose' => $purpose,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $usage
 * @param  array<string, mixed>  $meta
 * @param  list<array<string, mixed>>  $steps
 */
function chatHealthMessage(User $user, string $conversationId, string $role = 'assistant', string $content = 'Done.', array $usage = [], array $meta = [], array $steps = [], string $origin = 'typed', ?CarbonImmutable $at = null): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => $role,
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => json_encode($steps, JSON_THROW_ON_ERROR),
        'usage' => json_encode($usage, JSON_THROW_ON_ERROR),
        'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
        'origin' => $origin,
        'created_at' => $at ?? now(),
        'updated_at' => $at ?? now(),
    ]);
}

/** @return list<array<string, mixed>> */
function chatHealthToolStep(string $tool, string $result): array
{
    return [[
        'content' => '',
        'tool_calls' => [['id' => (string) Str::uuid7(), 'name' => $tool, 'arguments' => [], 'result' => $result]],
        'reasoning' => '',
        'replay_blocks' => [],
        'provider_tool_calls' => [],
    ]];
}

/** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
function chatHealthWindow(): array
{
    return [now()->subDay(), now()->addMinute()];
}

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
});

it('reports error turns, tool errors and the heaviest workspace from message usage', function (): void {
    $conversation = chatHealthConversation($this->user);

    chatHealthMessage($this->user, $conversation, usage: ['input_tokens' => 400000], meta: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5']);
    chatHealthMessage($this->user, $conversation, meta: ['error' => true]);
    chatHealthMessage($this->user, $conversation, steps: chatHealthToolStep('CreateOpportunityTool', '{"error":"Step reference `$ref:x` points at a proposal that is already approved."}'));

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['turns'])->toBe(3)
        ->and($report['error_turns'])->toBe(1)
        ->and($report['tool_calls'])->toBe(1)
        ->and($report['tool_errors'])->toBe(['CreateOpportunityTool' => 1])
        ->and($report['reference_errors'])->toBe(1)
        ->and($report['top_workspaces'][0])->toBe([
            'workspace_id' => (string) $this->user->currentWorkspace->getKey(),
            'turns' => 3,
            'prompt_tokens' => 400000,
        ]);
});

it('prints zeros for a window with no turns', function (): void {
    $this->artisan('chat:health', ['--days' => 7, '--json' => true])->assertSuccessful();

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['turns'])->toBe(0)
        ->and($report['typed_turns'])->toBe(0)
        ->and($report['cache'])->toBe([])
        ->and($report['top_workspaces'])->toBe([])
        ->and($report['proposals'])->toBe([]);
});

it('counts timeouts, typed turns and blank replies', function (): void {
    $conversation = chatHealthConversation($this->user);

    chatHealthMessage($this->user, $conversation, role: 'user');
    chatHealthMessage($this->user, $conversation, role: 'user', origin: 'resume');
    chatHealthMessage($this->user, $conversation, content: TurnNotes::timeout(150), meta: ['error' => true]);
    chatHealthMessage($this->user, $conversation, content: __('The assistant encountered an error. Please try again.'), meta: ['error' => true]);
    chatHealthMessage($this->user, $conversation, content: TurnNotes::blankReply()['noted']);

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['typed_turns'])->toBe(1)
        ->and($report['turns'])->toBe(3)
        ->and($report['error_turns'])->toBe(2)
        ->and($report['timeouts'])->toBe(1)
        ->and($report['blank_replies'])->toBe(1);
});

it('reports cache follow-ups per provider and proposals by status', function (): void {
    $conversation = chatHealthConversation($this->user);
    $meta = ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'];
    $start = now()->subHour();

    chatHealthMessage($this->user, $conversation, usage: ['input_tokens' => 1000, 'cache_read_input_tokens' => 0], meta: $meta, at: $start);
    chatHealthMessage($this->user, $conversation, usage: ['input_tokens' => 1000, 'cache_read_input_tokens' => 800], meta: $meta, at: $start->addSeconds(60));
    chatHealthMessage($this->user, $conversation, usage: ['input_tokens' => 1000, 'cache_read_input_tokens' => 0], meta: $meta, at: $start->addSeconds(120));
    chatHealthMessage($this->user, $conversation, usage: ['input_tokens' => 1000, 'cache_read_input_tokens' => 0], meta: $meta, at: $start->addSeconds(1000));
    $setup = chatHealthConversation($this->user, 'setup');
    chatHealthMessage($this->user, $setup, role: 'user');

    PendingAction::query()->create([
        'workspace_id' => $this->user->currentWorkspace->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $conversation,
        'action_class' => CreateTask::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'task',
        'action_data' => ['title' => 'Follow up'],
        'display_data' => ['title' => 'Create Task', 'summary' => 'Create task'],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
    ]);

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['cache'])->toBe(['anthropic' => ['followups' => 2, 'zero_read' => 1, 'read_share' => 0.4]])
        ->and($report['proposals'])->toBe(['approved' => 1])
        ->and($report['setup_conversations'])->toBe(1)
        ->and($report['setup_conversations_typed'])->toBe(1);
});

it('prints the report table without json', function (): void {
    $this->artisan('chat:health')->expectsOutputToContain('turns')->assertSuccessful();
});

it('keeps a zero-input follow-up at a zero share and skips one with no cache figure', function (): void {
    $conversation = chatHealthConversation($this->user);
    $other = chatHealthConversation($this->user);
    $start = now()->subHour();

    chatHealthMessage($this->user, $conversation, meta: ['provider' => 'openai', 'model' => 'a'], at: $start);
    chatHealthMessage($this->user, $conversation, usage: ['cache_read_input_tokens' => 0], meta: ['provider' => 'openai', 'model' => 'a'], at: $start->addSeconds(30));
    chatHealthMessage($this->user, $other, meta: ['provider' => 'anthropic', 'model' => 'b'], at: $start);
    chatHealthMessage($this->user, $other, usage: [], meta: ['provider' => 'anthropic', 'model' => 'b'], at: $start->addSeconds(30));
    chatHealthMessage($this->user, $other, usage: ['cache_read_input_tokens' => null], meta: ['provider' => 'anthropic', 'model' => 'b'], at: $start->addSeconds(60));

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['cache'])->toBe(['openai' => ['followups' => 1, 'zero_read' => 1, 'read_share' => 0.0]]);
});

it('measures the follow-up gap to the start of the later turn', function (): void {
    $conversation = chatHealthConversation($this->user);
    $meta = ['provider' => 'anthropic', 'model' => 'm'];
    $start = now()->subHour();

    chatHealthMessage($this->user, $conversation, meta: $meta, at: $start);
    chatHealthMessage($this->user, $conversation, usage: ['input_tokens' => 10, 'cache_read_input_tokens' => 5], meta: [...$meta, 'duration_ms' => 40000], at: $start->addSeconds(330));

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['cache']['anthropic']['followups'])->toBe(1);
});

it('lists the five heaviest workspaces by prompt tokens', function (): void {
    $users = collect(range(1, 6))->map(fn (int $i): User => User::factory()->withPersonalWorkspace()->create());

    foreach ($users as $index => $user) {
        chatHealthMessage($user, chatHealthConversation($user), usage: ['input_tokens' => ($index + 1) * 100]);
    }

    $top = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow())['top_workspaces'];

    expect(array_column($top, 'prompt_tokens'))->toBe([600, 500, 400, 300, 200])
        ->and($top[0]['workspace_id'])->toBe((string) $users[5]->currentWorkspace->getKey());
});

it('skips an errored turn tool calls and cache figures', function (): void {
    $conversation = chatHealthConversation($this->user);
    $meta = ['provider' => 'anthropic', 'model' => 'm', 'error' => true];

    chatHealthMessage($this->user, $conversation, meta: $meta, steps: chatHealthToolStep('T', '{"error":"x"}'));
    chatHealthMessage($this->user, $conversation, usage: ['input_tokens' => 9, 'cache_read_input_tokens' => 0], meta: $meta, steps: chatHealthToolStep('T', '{"error":"x"}'));

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['tool_calls'])->toBe(0)
        ->and($report['tool_errors'])->toBe([])
        ->and($report['cache'])->toBe([]);
});

it('counts a tool-only turn with empty content as not blank and a whitespace reply as blank', function (): void {
    $conversation = chatHealthConversation($this->user);

    chatHealthMessage($this->user, $conversation, content: '', steps: chatHealthToolStep('T', '{"ok":true}'));
    chatHealthMessage($this->user, $conversation, content: "  \n ");
    chatHealthMessage($this->user, $conversation, content: ' ');

    expect(resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow())['blank_replies'])->toBe(2);
});

it('counts a tool call stored as failed with a plain-string result', function (): void {
    $conversation = chatHealthConversation($this->user);
    $steps = chatHealthToolStep('CreateTaskTool', 'Tool exploded');
    $steps[0]['tool_calls'][0]['failed'] = true;

    chatHealthMessage($this->user, $conversation, steps: $steps);

    expect(resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow())['tool_errors'])->toBe(['CreateTaskTool' => 1]);
});

it('keeps the read share at or under one when input tokens predate the cache legs', function (): void {
    $conversation = chatHealthConversation($this->user);
    $meta = ['provider' => 'anthropic', 'model' => 'm'];
    $start = now()->subHour();

    chatHealthMessage($this->user, $conversation, meta: $meta, at: $start);
    chatHealthMessage($this->user, $conversation, usage: ['input_tokens' => 100, 'cache_read_input_tokens' => 500], meta: $meta, at: $start->addSeconds(30));

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['cache']['anthropic']['read_share'])->toBe(1.0)
        ->and($report['top_workspaces'][0]['prompt_tokens'])->toBe(500);
});

it('scans turns of a conversation with no workspace without keying them', function (): void {
    $conversation = chatHealthConversation($this->user, withoutWorkspace: true);

    chatHealthMessage($this->user, $conversation, steps: chatHealthToolStep('T', '{"ok":true}'));

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['turns'])->toBe(1)
        ->and($report['tool_calls'])->toBe(1)
        ->and($report['top_workspaces'])->toBe([]);
});

it('does not count a setup conversation holding only a resume row as typed', function (): void {
    $setup = chatHealthConversation($this->user, 'setup');
    chatHealthMessage($this->user, $setup, role: 'user', origin: 'resume');

    $report = resolve(ChatHealthQuery::class)->forWindow(...chatHealthWindow());

    expect($report['setup_conversations'])->toBe(1)
        ->and($report['setup_conversations_typed'])->toBe(0);
});
