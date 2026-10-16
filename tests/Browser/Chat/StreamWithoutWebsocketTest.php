<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Support\TurnPresence;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);

function sendWithoutWebsocket(AwaitableWebpage $page, string $text): void
{
    $resolveInterface = ChatBrowser::resolveInterface();

    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            window.Echo = null;
            data.turnPollMs = 200;
            data.localEditor().setText('{$text}');
            data.sendMessage();

            return true;
        })();
    JS);

    $page->assertScript(<<<JS
        (() => {
            {$resolveInterface}
            return data.messages.find((m) => m.role === 'user')?.sendState ?? null;
        })()
    JS, 'sent');
}

function persistReply(User $user, string $conversationId, string $content): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'assistant',
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function endTurn(string $conversationId): void
{
    TurnPresence::clear($conversationId, TurnPresence::current($conversationId)['turn_id']);
}

function lastAssistantBubble(): string
{
    $resolveInterface = ChatBrowser::resolveInterface();

    return <<<JS
        (() => {
            {$resolveInterface}
            const bubble = data.messages.findLast((m) => m.role === 'assistant');
            return JSON.stringify({
                content: bubble?.content ?? null,
                error: bubble?.streamError ?? null,
                streaming: data.isStreaming,
            });
        })()
    JS;
}

it('shows the saved reply when no websocket ever delivers the stream', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'No websocket');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'hello without a socket');

    persistReply($user, $conversationId, 'Saved while the socket was silent.');
    endTurn($conversationId);

    $page->assertScript(lastAssistantBubble(), json_encode([
        'content' => 'Saved while the socket was silent.',
        'error' => null,
        'streaming' => false,
    ]));
});

it('keeps waiting while the turn is still running', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Still running');
    persistReply($user, $conversationId, 'The reply to an earlier turn.');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'a slow question');

    $page->wait(1)->assertScript(lastAssistantBubble(), json_encode([
        'content' => '',
        'error' => null,
        'streaming' => true,
    ]));
});

it('reports a turn that ended without a reply and never repeats the previous one', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Failed turn');
    persistReply($user, $conversationId, 'The reply to an earlier turn.');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'a question that fails');

    endTurn($conversationId);

    $page->assertScript(lastAssistantBubble(), json_encode([
        'content' => '',
        'error' => 'The assistant encountered an error. Please try again.',
        'streaming' => false,
    ]));
});

it('shows the saved reply when the inactivity watchdog runs out', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Watchdog');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'hello watchdog');

    $resolveInterface = ChatBrowser::resolveInterface();
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.stopWatchingTurn();
            return true;
        })();
    JS);

    persistReply($user, $conversationId, 'Saved before the watchdog fired.');
    endTurn($conversationId);

    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.startStreamTimeout(50);
            return true;
        })();
    JS);

    $page->assertScript(lastAssistantBubble(), json_encode([
        'content' => 'Saved before the watchdog fired.',
        'error' => null,
        'streaming' => false,
    ]));
});

it('clears an earlier error once a later turn delivers its reply', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Recovered');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'a question that fails');
    endTurn($conversationId);

    $page->assertScript(lastAssistantBubble(), json_encode([
        'content' => '',
        'error' => 'The assistant encountered an error. Please try again.',
        'streaming' => false,
    ]));

    sendWithoutWebsocket($page, 'the same question again');
    persistReply($user, $conversationId, 'Answered on the second try.');
    endTurn($conversationId);

    $resolveInterface = ChatBrowser::resolveInterface();

    $page->assertScript(lastAssistantBubble(), json_encode([
        'content' => 'Answered on the second try.',
        'error' => null,
        'streaming' => false,
    ]))->assertScript(<<<JS
        (() => {
            {$resolveInterface}
            return data.messages.filter((m) => m.role === 'assistant' && m.streamError).length;
        })()
    JS, 0);
});

it('shows the saved reply after a reload in the middle of a turn', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Reloaded');
    TurnPresence::begin($conversationId, turnId: 'turn-reloaded', message: 'asked before the reload');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            return true;
        })();
    JS);

    persistReply($user, $conversationId, 'Saved after the reload.');
    endTurn($conversationId);

    $page->wait(3)->assertScript(lastAssistantBubble(), json_encode([
        'content' => 'Saved after the reload.',
        'error' => null,
        'streaming' => false,
    ]));
});

it('shows the reply to a turn resumed by an approval', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Resumed');
    persistReply($user, $conversationId, 'Here is the proposal.');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    TurnPresence::begin($conversationId, turnId: 'turn-resumed', message: '');

    $resolveInterface = ChatBrowser::resolveInterface();
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.turnPollMs = 200;
            window.Livewire.dispatch('chat:resuming', { context: data.context });
            return true;
        })();
    JS);

    $page->wait(1);
    persistReply($user, $conversationId, 'Done. The company is created.');
    endTurn($conversationId);

    $page->assertScript(<<<JS
        (() => {
            {$resolveInterface}
            return data.messages.filter((m) => m.role === 'assistant').length;
        })()
    JS, 2)
        ->assertScript(lastAssistantBubble(), json_encode([
            'content' => 'Done. The company is created.',
            'error' => null,
            'streaming' => false,
        ]));
});

it('shows the first reply of a new chat', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = ChatBrowser::logIn($user, $workspace->slug)
        ->navigate("/app/{$workspace->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'the first message of a new chat');

    $conversationId = (string) DB::table('agent_conversations')
        ->where('participant_id', (string) $user->getKey())
        ->value('id');

    persistReply($user, $conversationId, 'The first reply.');
    endTurn($conversationId);

    $page->assertScript(lastAssistantBubble(), json_encode([
        'content' => 'The first reply.',
        'error' => null,
        'streaming' => false,
    ]));
});

it('keeps waiting past the watchdog while the server still runs the turn', function (): void {
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'Slow model');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    sendWithoutWebsocket($page, 'a question for a slow model');

    $resolveInterface = ChatBrowser::resolveInterface();
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.startStreamTimeout(50);
            return true;
        })();
    JS);

    $page->wait(1)->assertScript(lastAssistantBubble(), json_encode([
        'content' => '',
        'error' => null,
        'streaming' => true,
    ]));

    persistReply($user, $conversationId, 'Answered after the watchdog.');
    endTurn($conversationId);

    $page->assertScript(lastAssistantBubble(), json_encode([
        'content' => 'Answered after the watchdog.',
        'error' => null,
        'streaming' => false,
    ]));
});
