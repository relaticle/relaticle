<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Agents\NextStepSuggester;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Events\NextStepsSuggested;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Jobs\SuggestNextSteps;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Support\NextSteps;
use Relaticle\Chat\Tools\Task\CreateTaskTool;

mutates(SuggestNextSteps::class, NextSteps::class);

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

    $this->conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $this->conversationId,
        'participant_type' => $this->user->getMorphClass(),
        'participant_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Workspace setup',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

/**
 * @param  array<string, mixed>  $meta
 */
function seedSuggestibleMessage(string $role, string $content, array $meta = []): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversation_messages')->insert([
        'id' => $id,
        'conversation_id' => test()->conversationId,
        'participant_type' => test()->user->getMorphClass(),
        'participant_id' => (string) test()->user->getKey(),
        'agent' => CrmAssistant::class,
        'role' => $role,
        'content' => $content,
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @return array<int, array{label: string, prompt: string}>
 */
function persistedNextSteps(string $messageId): array
{
    return NextSteps::fromMeta(
        (string) DB::table('agent_conversation_messages')->where('id', $messageId)->value('meta')
    );
}

it('persists the drafted steps on the assistant message and broadcasts them', function (): void {
    Event::fake([NextStepsSuggested::class]);
    NextStepSuggester::fake([['suggestions' => [
        ['label' => 'Import your companies', 'prompt' => 'Help me import my companies from a file'],
        ['label' => 'Add Acme Corp', 'prompt' => 'Create a company called Acme Corp'],
    ]]]);

    $messageId = seedSuggestibleMessage('assistant', 'Your workspace is empty.');

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: 'What can you help me with?',
        reply: 'Your workspace is empty.',
        provider: 'anthropic',
    ))->handle();

    expect(persistedNextSteps($messageId))->toBe([
        ['label' => 'Import your companies', 'prompt' => 'Help me import my companies from a file'],
        ['label' => 'Add Acme Corp', 'prompt' => 'Create a company called Acme Corp'],
    ]);

    Event::assertDispatched(
        NextStepsSuggested::class,
        fn (NextStepsSuggested $e): bool => $e->conversationId === $this->conversationId
            && count($e->steps) === 2
            && $e->steps[0]['label'] === 'Import your companies',
    );
});

it('sends the reply, the message and the tools it used to the suggester', function (): void {
    NextStepSuggester::fake([['suggestions' => [['label' => 'Add a note', 'prompt' => 'Add a note to Acme Corp']]]]);

    $messageId = seedSuggestibleMessage('assistant', 'Acme Corp has two open deals.');

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: 'how is acme doing',
        reply: 'Acme Corp has two open deals.',
        provider: 'anthropic',
        toolNames: ['GetCompanyTool', 'ListOpportunitiesTool', 'GetCompanyTool'],
    ))->handle();

    NextStepSuggester::assertPrompted(
        fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '<reply>Acme Corp has two open deals.</reply>')
            && str_contains($prompt->prompt, '<message>how is acme doing</message>')
            && str_contains($prompt->prompt, '<tools>GetCompanyTool, ListOpportunitiesTool</tools>'),
    );
});

it('omits the message block for a turn the user never typed', function (): void {
    NextStepSuggester::fake([['suggestions' => [['label' => 'Add a note', 'prompt' => 'Add a note to Acme Corp']]]]);

    $messageId = seedSuggestibleMessage('assistant', 'Done, I created the task.');

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: '',
        reply: 'Done, I created the task.',
        provider: 'anthropic',
    ))->handle();

    NextStepSuggester::assertPrompted(
        fn (AgentPrompt $prompt): bool => ! str_contains($prompt->prompt, '<message>'),
    );
});

it('caps the strip at three steps and drops blank or duplicate ones', function (): void {
    NextStepSuggester::fake([['suggestions' => [
        ['label' => 'First', 'prompt' => 'Do the first thing'],
        ['label' => '', 'prompt' => 'Do a nameless thing'],
        ['label' => 'Second', 'prompt' => ''],
        ['label' => 'First again', 'prompt' => 'DO THE FIRST THING'],
        ['label' => 'Third', 'prompt' => 'Do the third thing'],
        ['label' => 'Fourth', 'prompt' => 'Do the fourth thing'],
        ['label' => 'Fifth', 'prompt' => 'Do the fifth thing'],
    ]]]);

    $messageId = seedSuggestibleMessage('assistant', 'Here you go.');

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: 'anything',
        reply: 'Here you go.',
        provider: 'anthropic',
    ))->handle();

    expect(array_column(persistedNextSteps($messageId), 'label'))
        ->toBe(['First', 'Third', 'Fourth']);
});

it('truncates a label or prompt the model wrote too long for the strip', function (): void {
    NextStepSuggester::fake([['suggestions' => [[
        'label' => str_repeat('a', 80),
        'prompt' => str_repeat('b', 400),
    ]]]]);

    $messageId = seedSuggestibleMessage('assistant', 'Here you go.');

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: 'anything',
        reply: 'Here you go.',
        provider: 'anthropic',
    ))->handle();

    $steps = persistedNextSteps($messageId);

    expect($steps[0]['label'])->toHaveLength(48)
        ->and($steps[0]['prompt'])->toHaveLength(160);
});

it('writes nothing when the model offers no steps', function (): void {
    Event::fake([NextStepsSuggested::class]);
    NextStepSuggester::fake([['suggestions' => []]]);

    $messageId = seedSuggestibleMessage('assistant', 'Which Acme did you mean?');

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: 'tell me about acme',
        reply: 'Which Acme did you mean?',
        provider: 'anthropic',
    ))->handle();

    expect(persistedNextSteps($messageId))->toBe([]);
    Event::assertNotDispatched(NextStepsSuggested::class);
});

it('leaves the message untouched when the model call fails', function (): void {
    Event::fake([NextStepsSuggested::class]);
    NextStepSuggester::fake(fn (): never => throw new RuntimeException('provider exploded'));

    $messageId = seedSuggestibleMessage('assistant', 'Here you go.', ['duration_ms' => 1200]);

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: 'anything',
        reply: 'Here you go.',
        provider: 'anthropic',
    ))->handle();

    expect(persistedNextSteps($messageId))->toBe([]);
    Event::assertNotDispatched(NextStepsSuggested::class);
});

it('keeps the other meta the turn already wrote', function (): void {
    NextStepSuggester::fake([['suggestions' => [['label' => 'Add a note', 'prompt' => 'Add a note to Acme Corp']]]]);

    $messageId = seedSuggestibleMessage('assistant', 'Here you go.', ['duration_ms' => 1200]);

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: 'anything',
        reply: 'Here you go.',
        provider: 'anthropic',
    ))->handle();

    $meta = json_decode(
        (string) DB::table('agent_conversation_messages')->where('id', $messageId)->value('meta'),
        associative: true,
    );

    expect($meta['duration_ms'])->toBe(1200)
        ->and($meta['next_steps'])->toHaveCount(1);
});

it('drops the steps when the message they belong to is gone', function (): void {
    Event::fake([NextStepsSuggested::class]);
    NextStepSuggester::fake([['suggestions' => [['label' => 'Add a note', 'prompt' => 'Add a note to Acme Corp']]]]);

    $messageId = seedSuggestibleMessage('assistant', 'Here you go.');
    DB::table('agent_conversation_messages')->where('id', $messageId)->delete();

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: 'anything',
        reply: 'Here you go.',
        provider: 'anthropic',
    ))->handle();

    Event::assertNotDispatched(NextStepsSuggested::class);
});

it('never calls the model when suggestions are switched off', function (): void {
    config()->set('chat.next_steps.enabled', false);

    NextStepSuggester::fake([['suggestions' => [['label' => 'Add a note', 'prompt' => 'Add a note to Acme Corp']]]]);

    $messageId = seedSuggestibleMessage('assistant', 'Here you go.');

    (new SuggestNextSteps(
        conversationId: $this->conversationId,
        messageId: $messageId,
        message: 'anything',
        reply: 'Here you go.',
        provider: 'anthropic',
    ))->handle();

    NextStepSuggester::assertNeverPrompted();
    expect(persistedNextSteps($messageId))->toBe([]);
});

it('dispatches the suggester at the end of a turn with what the turn produced', function (): void {
    Queue::fake();
    CrmAssistant::fake(['Acme Corp has two open deals worth $30,000.']);

    (new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'how is acme doing',
        conversationId: $this->conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
    ))->handle(resolve(CreditService::class));

    Queue::assertPushed(
        SuggestNextSteps::class,
        fn (SuggestNextSteps $job): bool => $job->conversationId === $this->conversationId
            && $job->message === 'how is acme doing'
            && $job->reply === 'Acme Corp has two open deals worth $30,000.',
    );
});

it('offers no steps on a turn that ends waiting for a decision', function (): void {
    Queue::fake();

    // Proposed from inside the fake turn, exactly as the real write tool does:
    // seeding it beforehand would not survive, because a new turn supersedes
    // every proposal the user left undecided before it started.
    CrmAssistant::fake(function (): string {
        $tool = resolve(CreateTaskTool::class);
        $tool->setConversationId(test()->conversationId);
        $tool->handle(new Request(['records' => [['title' => 'Call Acme']]]));

        return 'I have drafted that task for you.';
    });

    (new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'create a task to call acme',
        conversationId: $this->conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
    ))->handle(resolve(CreditService::class));

    expect(PendingAction::query()->where('conversation_id', $this->conversationId)
        ->where('status', PendingActionStatus::Pending)->count())->toBe(1);

    Queue::assertNotPushed(SuggestNextSteps::class);
});

it('paints the persisted steps back onto a reloading page', function (): void {
    seedSuggestibleMessage('user', 'What can you help me with?');
    seedSuggestibleMessage('assistant', 'Your workspace is empty.', [
        'next_steps' => [['label' => 'Import your companies', 'prompt' => 'Help me import my companies']],
    ]);

    $messages = Livewire::test(ChatInterface::class, ['conversationId' => $this->conversationId])
        ->get('messages');

    expect($messages[0]['next_steps'])->toBe([])
        ->and($messages[1]['next_steps'])->toBe([
            ['label' => 'Import your companies', 'prompt' => 'Help me import my companies'],
        ]);
});

it('hands the persisted steps to the turn-end reconcile', function (): void {
    $messageId = seedSuggestibleMessage('assistant', 'Your workspace is empty.', [
        'next_steps' => [['label' => 'Import your companies', 'prompt' => 'Help me import my companies']],
    ]);

    Livewire::test(ChatInterface::class)
        ->call('latestAssistantMessage', $this->conversationId)
        ->assertReturned(fn (array $payload): bool => $payload['id'] === $messageId
            && $payload['next_steps'] === [
                ['label' => 'Import your companies', 'prompt' => 'Help me import my companies'],
            ]);
});

it('reads no steps off a message that predates the feature', function (): void {
    $messageId = seedSuggestibleMessage('assistant', 'An older reply.');

    expect(persistedNextSteps($messageId))->toBe([]);
});
