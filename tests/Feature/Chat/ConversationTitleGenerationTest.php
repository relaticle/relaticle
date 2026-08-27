<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Relaticle\Chat\Agents\ConversationTitler;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Events\ConversationTitleGenerated;
use Relaticle\Chat\Jobs\GenerateConversationTitle;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Support\ConversationTitleGate;
use Relaticle\Chat\Support\TitleSanitizer;
use Tests\Helpers\ChatDocument;

mutates(GenerateConversationTitle::class, TitleSanitizer::class, ConversationTitleGate::class);

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

function seedTitlingConversation(string $title): string
{
    $id = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => test()->user->getMorphClass(),
        'participant_id' => (string) test()->user->getKey(),
        'team_id' => test()->team->getKey(),
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @param  array<string, string>  $meta
 */
function seedTitlingMessage(string $conversationId, string $role, string $content, array $meta = []): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
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
}

it('stores the raw first message as the provisional title on create', function (): void {
    $response = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Create a follow-up task for Sarah at Acme next Tuesday'),
    ])->assertOk();

    expect(AgentConversation::query()->find($response->json('conversation_id'))->title)
        ->toBe('Create a follow-up task for Sarah at Acme next Tuesday');
});

it('dispatches title generation alongside the first turn', function (): void {
    Queue::fake();

    $conversationId = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Create a follow-up task for Sarah at Acme'),
    ])->assertOk()->json('conversation_id');

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('Create a follow-up task for Sarah at Acme'),
    ])->assertOk();

    Queue::assertPushed(
        GenerateConversationTitle::class,
        fn (GenerateConversationTitle $job): bool => $job->conversationId === $conversationId
            && $job->provisionalTitle === 'Create a follow-up task for Sarah at Acme'
            && $job->message === 'Create a follow-up task for Sarah at Acme',
    );
});

it('does not re-title a conversation that already has messages', function (): void {
    Queue::fake();

    $conversationId = seedTitlingConversation('Existing chat');
    seedTitlingMessage($conversationId, 'user', 'Earlier message');
    seedTitlingMessage($conversationId, 'assistant', 'Earlier reply');

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('A second message'),
    ])->assertOk();

    Queue::assertNotPushed(GenerateConversationTitle::class);
});

it('replaces the provisional title and broadcasts the new one', function (): void {
    Event::fake([ConversationTitleGenerated::class]);
    ConversationTitler::fake([['has_topic' => true, 'title' => 'Follow Up With Acme']]);

    $conversationId = seedTitlingConversation('Create a follow-up task for Sarah at Acme next Tuesday');

    (new GenerateConversationTitle(
        conversationId: $conversationId,
        provisionalTitle: 'Create a follow-up task for Sarah at Acme next Tuesday',
        message: 'Create a follow-up task for Sarah at Acme next Tuesday',
        provider: 'anthropic',
    ))->handle();

    expect(AgentConversation::query()->find($conversationId)->title)->toBe('Follow Up With Acme');

    ConversationTitler::assertPrompted(
        fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, 'Sarah at Acme'),
    );

    Event::assertDispatched(
        ConversationTitleGenerated::class,
        fn (ConversationTitleGenerated $e): bool => $e->conversationId === $conversationId
            && $e->title === 'Follow Up With Acme',
    );
});

it('never overwrites a title the user renamed while the model was thinking', function (): void {
    Event::fake([ConversationTitleGenerated::class]);
    ConversationTitler::fake([['has_topic' => true, 'title' => 'Follow Up With Acme']]);

    $conversationId = seedTitlingConversation('My own name for this');

    (new GenerateConversationTitle(
        conversationId: $conversationId,
        provisionalTitle: 'Create a follow-up task for Sarah at Acme next Tuesday',
        message: 'Create a follow-up task for Sarah at Acme next Tuesday',
        provider: 'anthropic',
    ))->handle();

    expect(AgentConversation::query()->find($conversationId)->title)->toBe('My own name for this');

    Event::assertNotDispatched(ConversationTitleGenerated::class);
});

it('keeps the provisional title when the model call fails', function (): void {
    Event::fake([ConversationTitleGenerated::class]);
    ConversationTitler::fake(fn (): never => throw new RuntimeException('provider exploded'));

    $conversationId = seedTitlingConversation('Create a follow-up task for Sarah');

    (new GenerateConversationTitle(
        conversationId: $conversationId,
        provisionalTitle: 'Create a follow-up task for Sarah',
        message: 'Create a follow-up task for Sarah',
        provider: 'anthropic',
    ))->handle();

    expect(AgentConversation::query()->find($conversationId)->title)->toBe('Create a follow-up task for Sarah');

    Event::assertNotDispatched(ConversationTitleGenerated::class);
});

it('leaves the title alone when generation is switched off', function (): void {
    config()->set('chat.title_generation.enabled', false);

    Event::fake([ConversationTitleGenerated::class]);
    ConversationTitler::fake([['has_topic' => true, 'title' => 'Follow Up With Acme']]);

    $conversationId = seedTitlingConversation('Create a follow-up task for Sarah');

    (new GenerateConversationTitle(
        conversationId: $conversationId,
        provisionalTitle: 'Create a follow-up task for Sarah',
        message: 'Create a follow-up task for Sarah',
        provider: 'anthropic',
    ))->handle();

    expect(AgentConversation::query()->find($conversationId)->title)->toBe('Create a follow-up task for Sarah');

    Event::assertNotDispatched(ConversationTitleGenerated::class);
    ConversationTitler::assertNeverPrompted();
});

it('strips the decorations a titling model adds', function (string $raw, string $expected): void {
    $title = TitleSanitizer::generated($raw);

    expect($title)->toBe($expected)
        ->and(mb_check_encoding($title, 'UTF-8'))->toBeTrue();
})->with([
    'wrapping double quotes' => ['"Follow Up With Acme"', 'Follow Up With Acme'],
    'smart quotes' => ['“Follow Up With Acme”', 'Follow Up With Acme'],
    'title prefix' => ['Title: Follow Up With Acme', 'Follow Up With Acme'],
    'trailing full stop' => ['Follow Up With Acme.', 'Follow Up With Acme'],
    'collapsed whitespace' => ["Follow  Up\nWith Acme", 'Follow Up With Acme'],
    'question mark survives' => ['How Many Deals Closed?', 'How Many Deals Closed?'],
    // Byte-wise trimming of a UTF-8 quote list would eat the trailing byte of
    // "ś" (U+015B) and hand Postgres an invalid string.
    'multibyte tail survives' => ['Zapytanie Klientaś', 'Zapytanie Klientaś'],
    'quoted multibyte title' => ['„Cześć Zespole”', 'Cześć Zespole'],
    'over-long title is capped' => [str_repeat('a', 90), str_repeat('a', 60)],
]);

it('does not dispatch titling for a chat the user already renamed', function (): void {
    Queue::fake();

    $conversationId = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('Prepare the Stark Industries quarterly business review deck'),
    ])->assertOk()->json('conversation_id');

    $this->postJson(route('chat.rename', ['conversationId' => $conversationId]), [
        'title' => 'MY OWN NAME',
    ])->assertOk();

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('Prepare the Stark Industries quarterly business review deck'),
    ])->assertOk();

    Queue::assertNotPushed(GenerateConversationTitle::class);
    expect(AgentConversation::query()->find($conversationId)->title)->toBe('MY OWN NAME');
});

it('keeps the provisional title when the opener carries no topic to name', function (): void {
    Event::fake([ConversationTitleGenerated::class]);
    ConversationTitler::fake([['has_topic' => false, 'title' => '']]);

    $conversationId = seedTitlingConversation('hey');

    (new GenerateConversationTitle(
        conversationId: $conversationId,
        provisionalTitle: 'hey',
        message: 'hey',
        provider: 'anthropic',
    ))->handle();

    expect(AgentConversation::query()->find($conversationId)->title)->toBe('hey');

    Event::assertNotDispatched(ConversationTitleGenerated::class);
});

it('gives a later message a chance to title a chat whose opener had no topic', function (): void {
    Queue::fake();

    $conversationId = seedTitlingConversation('hey');
    seedTitlingMessage($conversationId, 'user', 'hey');
    seedTitlingMessage($conversationId, 'assistant', 'Hi! How can I help?');

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('Draft a renewal proposal for Globex'),
    ])->assertOk();

    Queue::assertPushed(
        GenerateConversationTitle::class,
        fn (GenerateConversationTitle $job): bool => $job->provisionalTitle === 'hey'
            && $job->message === 'Draft a renewal proposal for Globex',
    );
});

it('stops attempting to title after the opening few messages', function (): void {
    Queue::fake();

    $conversationId = seedTitlingConversation('hey');
    foreach (['hey', 'still there?', 'hello?'] as $content) {
        seedTitlingMessage($conversationId, 'user', $content);
    }

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('Draft a renewal proposal for Globex'),
    ])->assertOk();

    Queue::assertNotPushed(GenerateConversationTitle::class);
});

it('gives the titler the record the user was viewing', function (): void {
    Queue::fake();

    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);

    $conversationId = $this->postJson(route('chat.conversations.create'), [
        'document' => ChatDocument::fromText('add a note here'),
    ])->assertOk()->json('conversation_id');

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('add a note here'),
        'page_context' => ['type' => 'company', 'id' => (string) $company->getKey()],
    ])->assertOk();

    Queue::assertPushed(
        GenerateConversationTitle::class,
        fn (GenerateConversationTitle $job): bool => $job->pageContext !== null
            && $job->pageContext['label'] === 'Acme Corp'
            && $job->pageContext['type'] === 'company',
    );
});

it('names a record-less message from the record the user was viewing', function (): void {
    ConversationTitler::fake([['has_topic' => true, 'title' => 'Note On Acme Corp']]);

    $conversationId = seedTitlingConversation('add a note here');

    (new GenerateConversationTitle(
        conversationId: $conversationId,
        provisionalTitle: 'add a note here',
        message: 'add a note here',
        provider: 'anthropic',
        pageContext: ['type' => 'company', 'id' => '1', 'label' => 'Acme Corp'],
    ))->handle();

    ConversationTitler::assertPrompted(
        fn (AgentPrompt $prompt): bool => str_contains($prompt->prompt, '<viewing>Acme Corp (company)</viewing>')
            && str_contains($prompt->prompt, '<message>add a note here</message>'),
    );

    expect(AgentConversation::query()->find($conversationId)->title)->toBe('Note On Acme Corp');
});

it('never pays for a title it could no longer apply', function (): void {
    Event::fake([ConversationTitleGenerated::class]);
    ConversationTitler::fake([['has_topic' => true, 'title' => 'Follow Up With Acme']]);

    $conversationId = seedTitlingConversation('A title the first attempt already wrote');

    (new GenerateConversationTitle(
        conversationId: $conversationId,
        provisionalTitle: 'Create a follow-up task for Sarah at Acme',
        message: 'Create a follow-up task for Sarah at Acme',
        provider: 'anthropic',
    ))->handle();

    ConversationTitler::assertNeverPrompted();
    Event::assertNotDispatched(ConversationTitleGenerated::class);
    expect(AgentConversation::query()->find($conversationId)->title)->toBe('A title the first attempt already wrote');
});

it('titles from the assistant reply when the opening message named nothing', function (): void {
    Queue::fake();
    CrmAssistant::fake(['Globex has three open opportunities worth $45,000.']);

    $conversationId = seedTitlingConversation('hey');

    (new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'hey',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
    ))->handle(resolve(CreditService::class));

    Queue::assertPushed(
        GenerateConversationTitle::class,
        fn (GenerateConversationTitle $job): bool => $job->conversationId === $conversationId
            && $job->provisionalTitle === 'hey'
            && $job->reply === 'Globex has three open opportunities worth $45,000.',
    );
});

it('does not re-title at turn end when the conversation already has a generated title', function (): void {
    Queue::fake();
    CrmAssistant::fake(['Globex has three open opportunities.']);

    $conversationId = seedTitlingConversation('Globex Opportunity Review');

    (new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'how is globex doing',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
    ))->handle(resolve(CreditService::class));

    Queue::assertNotPushed(GenerateConversationTitle::class);
});

it('does not let approval echoes and resumed turns burn the titling window', function (): void {
    Queue::fake();

    $conversationId = seedTitlingConversation('hey');
    seedTitlingMessage($conversationId, 'user', 'hey');
    seedTitlingMessage($conversationId, 'assistant', 'Hi! How can I help?');
    seedTitlingMessage($conversationId, 'user', '[approval] approved');
    seedTitlingMessage($conversationId, 'user', 'The proposals from your last turn have just been decided.', ['kind' => 'continuation']);

    $this->postJson(route('chat.send', ['conversation' => $conversationId]), [
        'document' => ChatDocument::fromText('Draft a renewal proposal for Globex'),
    ])->assertOk();

    Queue::assertPushed(
        GenerateConversationTitle::class,
        fn (GenerateConversationTitle $job): bool => $job->provisionalTitle === 'hey',
    );
});

it('titles at turn end from what the user typed, not from the rows the system wrote', function (): void {
    Queue::fake();
    CrmAssistant::fake(['Globex has three open opportunities.']);

    $conversationId = seedTitlingConversation('hey');
    seedTitlingMessage($conversationId, 'user', 'hey');
    seedTitlingMessage($conversationId, 'assistant', 'Hi! How can I help?');
    seedTitlingMessage($conversationId, 'user', '[approval] approved');
    seedTitlingMessage($conversationId, 'user', 'The proposals from your last turn have just been decided.', ['kind' => 'continuation']);

    (new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'how is globex doing',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
    ))->handle(resolve(CreditService::class));

    Queue::assertPushed(
        GenerateConversationTitle::class,
        fn (GenerateConversationTitle $job): bool => $job->provisionalTitle === 'hey'
            && $job->message === 'how is globex doing',
    );
});
