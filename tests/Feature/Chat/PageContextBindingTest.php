<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

mutates(ProcessChatMessage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->conversationId = '019df800-5555-7000-8000-000000000001';

    DB::table('agent_conversations')->insert([
        'id' => $this->conversationId,
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Queue::fake();
});

function sendWithPageContext(string $conversationId, ?array $pageContext): TestResponse
{
    return test()->postJson('/chat/'.$conversationId, array_filter([
        'document' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'summarize this']]]],
        ],
        'conversation_id' => $conversationId,
        'page_context' => $pageContext,
    ], static fn (mixed $value): bool => $value !== null));
}

it('binds the record the user is viewing to the turn', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $this->actingAs($this->user);

    sendWithPageContext($this->conversationId, [
        'type' => 'company',
        'id' => (string) $acme->getKey(),
    ])->assertOk();

    Queue::assertPushed(ProcessChatMessage::class, function (ProcessChatMessage $job) use ($acme): bool {
        return $job->pageContext !== null
            && $job->pageContext['type'] === 'company'
            && $job->pageContext['id'] === (string) $acme->getKey()
            && $job->pageContext['label'] === 'Acme';
    });
});

it('drops a page context pointing at another team record', function (): void {
    $otherUser = User::factory()->withPersonalTeam()->create();
    $theirs = Company::factory()->for($otherUser->currentTeam)->create(['name' => 'Theirs']);

    $this->actingAs($this->user);

    sendWithPageContext($this->conversationId, [
        'type' => 'company',
        'id' => (string) $theirs->getKey(),
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        static fn (ProcessChatMessage $job): bool => $job->pageContext === null,
    );
});

it('carries no page context when none is sent', function (): void {
    $this->actingAs($this->user);

    sendWithPageContext($this->conversationId, null)->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        static fn (ProcessChatMessage $job): bool => $job->pageContext === null,
    );
});

it('drops a page context with an unsupported type', function (): void {
    $acme = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    $this->actingAs($this->user);

    sendWithPageContext($this->conversationId, [
        'type' => 'invoice',
        'id' => (string) $acme->getKey(),
    ])->assertOk();

    Queue::assertPushed(
        ProcessChatMessage::class,
        static fn (ProcessChatMessage $job): bool => $job->pageContext === null,
    );
});

it('renders the bound record into the agent dynamic instructions', function (): void {
    $agent = resolve(CrmAssistant::class);

    $agent->withPageContext(['type' => 'company', 'id' => '01JABCDEF', 'label' => 'Acme']);

    expect($agent->dynamicInstructions())
        ->toContain('Acme')
        ->toContain('01JABCDEF')
        ->toContain('untrusted');
});

it('renders nothing when no record is bound', function (): void {
    $agent = resolve(CrmAssistant::class);

    $agent->withPageContext(null);

    expect($agent->dynamicInstructions())->not->toContain('currently viewing');
});

function seedCreditBalance(Team $team): void
{
    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
}

it('persists the bound record as a page_context row on the user message', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    seedCreditBalance($this->team);
    CrmAssistant::fake(['ok']);

    (new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'summarize this',
        conversationId: $this->conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        mentions: [],
        pageContext: ['type' => 'company', 'id' => (string) $company->getKey(), 'label' => 'Acme'],
    ))->handle(resolve(CreditService::class));

    $userMessage = DB::table('agent_conversation_messages')
        ->where('conversation_id', $this->conversationId)
        ->where('role', 'user')
        ->first();

    $row = DB::table('agent_conversation_message_mentions')
        ->where('message_id', $userMessage->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->source)->toBe('page_context')
        ->and($row->record_id)->toBe((string) $company->getKey())
        ->and($row->label)->toBe('Acme');
});

/**
 * The discriminating case: a message with BOTH a typed mention and a bound
 * page context must write TWO rows. The pre-fix `persistMentions()` guard
 * (`if ($this->mentions === []) return;`) only ever wrote the mention row and
 * silently dropped page context — asserting a bare "no row" absence can't
 * tell that apart from the feature simply not existing yet, so this is the
 * test that actually fails against the old code.
 */
it('writes both a mention row and a page_context row when a message has each', function (): void {
    $mentioned = Company::factory()->for($this->team)->create(['name' => 'Widgets Inc']);
    $viewed = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    seedCreditBalance($this->team);
    CrmAssistant::fake(['ok']);

    (new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'Tell me about @Widgets_Inc',
        conversationId: $this->conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        mentions: [['type' => 'company', 'id' => (string) $mentioned->getKey(), 'label' => 'Widgets Inc']],
        pageContext: ['type' => 'company', 'id' => (string) $viewed->getKey(), 'label' => 'Acme'],
    ))->handle(resolve(CreditService::class));

    $userMessage = DB::table('agent_conversation_messages')
        ->where('conversation_id', $this->conversationId)
        ->where('role', 'user')
        ->first();

    $rows = DB::table('agent_conversation_message_mentions')
        ->where('message_id', $userMessage->id)
        ->get()
        ->keyBy('source');

    expect($rows)->toHaveCount(2)
        ->and($rows['mention']->record_id)->toBe((string) $mentioned->getKey())
        ->and($rows['page_context']->record_id)->toBe((string) $viewed->getKey())
        ->and($rows['page_context']->label)->toBe('Acme');
});

it('writes no page_context row when no record is bound', function (): void {
    seedCreditBalance($this->team);
    CrmAssistant::fake(['ok']);

    (new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'hello',
        conversationId: $this->conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        mentions: [],
        pageContext: null,
    ))->handle(resolve(CreditService::class));

    expect(DB::table('agent_conversation_message_mentions')->count())->toBe(0);
});
