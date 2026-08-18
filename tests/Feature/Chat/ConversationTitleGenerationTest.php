<?php

declare(strict_types=1);

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
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Support\TitleSanitizer;
use Tests\Helpers\ChatDocument;

mutates(GenerateConversationTitle::class, TitleSanitizer::class);

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

function seedTitlingMessage(string $conversationId, string $role, string $content): void
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
        'meta' => '[]',
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
    ConversationTitler::fake([['title' => 'Follow Up With Acme']]);

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
    ConversationTitler::fake([['title' => 'Follow Up With Acme']]);

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
    ConversationTitler::fake([['title' => 'Follow Up With Acme']]);

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
