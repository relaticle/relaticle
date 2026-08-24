<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;

mutates(ChatInterface::class);

/**
 * Issue #509: same root cause as the draft-timer cross-write fixed in
 * c43a7bbfa. localEditor() (chat-interface.blade.php) resolves the composer
 * with a global `document.querySelector` by data-chat-context STRING, not
 * scoped to the component's own DOM subtree. Any callback that survives
 * destroy() and later calls localEditor() resolves whichever instance is
 * currently live under that context.
 *
 * unsubscribe() (window.Echo.leave) stops NEW events from being delivered
 * but cannot cancel a handler already mid-execution. handleStreamEnd()
 * awaits reconcileLatestAssistant()'s `$wire` round trip before reaching
 * flushQueuedSend(), which touches the editor via a further deferred
 * $nextTick. Note: handleStreamFailed() (also named in the issue) has no
 * internal await of its own. The delayed step it eventually reaches is the
 * SAME deferred $nextTick pattern, not a $wire round trip; the fix guards
 * that $nextTick directly rather than relying on an await gap that does not
 * exist for that specific handler.
 */
it('does not write a queued document from conversation A into conversation B\'s live composer', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'conv a', $conversationA);
    ChatBrowser::seedConversation($user, $team->getKey(), 'conv b', $conversationB);

    $editor = '[data-chat-context="conversation"] [contenteditable="true"]';
    $resolveInterface = ChatBrowser::resolveInterface();

    $page = ChatBrowser::logIn($user, $team->slug, $conversationA)
        ->assertSourceHas('placeholder="Ask anything..."');

    // Replace reconcileLatestAssistant() with a promise this test controls
    // the resolution of, deterministically opening the exact await window
    // handleStreamEnd() spends mid-continuation, since a fixed sleep() would race
    // the real navigation below and could produce a false pass. Stash the
    // release function on `window`: it is the one piece of state that
    // survives the wire:navigate switch this test performs next.
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.reconcileLatestAssistant = () => new Promise((resolve) => {
                window.__release509 = () => resolve(null);
            });
            data.queuedSend = {
                document: {
                    type: 'doc',
                    content: [{ type: 'paragraph', content: [{ type: 'text', text: 'MARKER_FROM_CONVERSATION_A' }] }],
                },
                model: null,
            };
            data.handleStreamEnd({ invocation_id: null });
            return true;
        })();
    JS);

    // The real sidebar wire:navigate link: this is what runs destroy() and
    // unsubscribe() on A's instance while its handleStreamEnd() continuation
    // above is still suspended on the mocked await.
    $page->click("nav[aria-label=\"Sidebar navigation\"] a[href*=\"{$conversationB}\"]")
        ->assertPathIs("/app/{$team->slug}/chats/{$conversationB}");

    $page->click($editor)->type($editor, 'B_OWN_TYPED_CONTENT');

    // Now let A's continuation resume: pre-fix, it resolves B's live editor
    // via the same-context global query and overwrites this text.
    $page->script(<<<'JS'
        (() => { window.__release509(); return true; })();
    JS);

    $bComposerText = null;
    for ($i = 0; $i < 30; $i++) {
        $bComposerText = $page->script(<<<'JS'
            (() => {
                const el = document.querySelector('[data-chat-context="conversation"] [contenteditable="true"]');
                return el ? el.textContent : null;
            })();
        JS);
        if ($bComposerText === 'B_OWN_TYPED_CONTENT') {
            break;
        }
        usleep(100_000);
    }

    expect($bComposerText)->toBe('B_OWN_TYPED_CONTENT');

    // Stronger than the composer-text check alone: pre-fix, flushQueuedSend()
    // does not stop at setDocument(); it goes on to call sendMessage() with
    // `this` still bound to A's dead instance (whose conversationId is still
    // A's), silently POSTing the queued text into conversation A behind the
    // user's back while they are looking at B. Confirmed live before this
    // fix: a real user + assistant row landed in conversation A this way.
    $conversationARowCount = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationA)
        ->count();
    expect($conversationARowCount)->toBe(0);

    $conversationBRowCount = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationB)
        ->count();
    expect($conversationBRowCount)->toBe(0);
});

it('still flushes a queued send into the editor when the instance was not torn down', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'conv single', $conversationId);

    $resolveInterface = ChatBrowser::resolveInterface();

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    // Same release-gate mock as the leak test above, but this time nothing
    // navigates away: the guard must not block the ordinary, same-instance
    // continuation, only the torn-down one.
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.reconcileLatestAssistant = () => new Promise((resolve) => {
                window.__release509happy = () => resolve(null);
            });
            data.queuedSend = {
                document: {
                    type: 'doc',
                    content: [{ type: 'paragraph', content: [{ type: 'text', text: 'QUEUED_HAPPY_PATH' }] }],
                },
                model: null,
            };
            data.handleStreamEnd({ invocation_id: null });
            return true;
        })();
    JS);

    $page->script(<<<'JS'
        (() => { window.__release509happy(); return true; })();
    JS);

    $userMessageCount = null;
    for ($i = 0; $i < 30; $i++) {
        $userMessageCount = $page->script(<<<JS
            (() => {
                {$resolveInterface}
                return data.messages.filter((m) => m.role === 'user' && m.content === 'QUEUED_HAPPY_PATH').length;
            })();
        JS);
        if ($userMessageCount === 1) {
            break;
        }
        usleep(100_000);
    }

    expect($userMessageCount)->toBe(1);
});

/**
 * Issue #509, the narrower gap left after the first fix above: handleStreamEnd()'s
 * own `destroyed` check (stream.js:377) guards the await gap it sits behind, but
 * flushQueuedSend() (send.js) opens a SEPARATE, later gap of its own via its
 * internal $nextTick. A wire:navigate teardown landing between that tick being
 * scheduled and it firing survives the earlier check entirely, since the check
 * already passed before flushQueuedSend() was ever called.
 *
 * flushQueuedSend() is called directly here rather than threaded through
 * handleStreamEnd(): that isolates the specific gap under test from the
 * already-covered await gap above. Alpine's nextTick is queueMicrotask ->
 * setTimeout, so the callback fires one macrotask after a script() call returns;
 * setting `destroyed` in a SECOND, later script() call would race that macrotask
 * over two real CDP round trips and could land after the tick already fired,
 * giving a test with no teeth in either direction. Scheduling the tick and
 * setting `destroyed` in the SAME synchronous script instead removes that race:
 * the tick's callback cannot interleave with either statement, so `destroyed` is
 * deterministically true by the time it runs, reproducing the exact ordering a
 * real teardown landing in that gap would produce.
 */
it('does not touch the editor or send when destroy lands between flushQueuedSend scheduling its tick and the tick firing', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'conv flush race', $conversationId);

    $editor = '[data-chat-context="conversation"] [contenteditable="true"]';
    $resolveInterface = ChatBrowser::resolveInterface();
    $conversationIdJson = json_encode($conversationId);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click($editor)->type($editor, 'OWN_TYPED_CONTENT');

    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.conversationId = {$conversationIdJson};
            data.queuedSend = {
                document: {
                    type: 'doc',
                    content: [{ type: 'paragraph', content: [{ type: 'text', text: 'MARKER_QUEUED_SEND' }] }],
                },
                model: null,
            };
            data.flushQueuedSend();
            data.destroyed = true;
            return true;
        })();
    JS);

    // Pre-fix: once the tick fires, setDocument() overwrites this with the
    // queued marker text. A fixed wait covers the whole window the deferred
    // tick could land in; the single read below then samples the settled state.
    $page->wait(2);

    $editorTextAfterRace = $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-chat-context="conversation"] [contenteditable="true"]');
            return el ? el.textContent : null;
        })();
    JS);

    expect($editorTextAfterRace)->toBe('OWN_TYPED_CONTENT');

    // Stronger than the composer-text check alone: pre-fix, the callback does not
    // stop at setDocument(); it goes on to call sendMessage() with `this` still
    // bound to the torn-down instance's own conversationId, silently pushing the
    // queued marker into this.messages and POSTing it server-side.
    $sentMarkerCount = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            return data.messages.filter((m) => m.content === 'MARKER_QUEUED_SEND').length;
        })();
    JS);

    expect($sentMarkerCount)->toBe(0);

    expect(DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->count())->toBe(0);
});

/**
 * The same class of cross-write, one layer up: switchConversation() repaints the
 * transcript from cache and swaps conversationId without touching the Echo
 * channel, so between the click and the wire:navigate remount the instance shows
 * B while still subscribed to A. Resuming after a proposal decision is what makes
 * a stream start in that window without this tab having sent anything.
 */
it('does not paint a stream still arriving for conversation A into the transcript of conversation B', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'conv a', $conversationA);
    ChatBrowser::seedConversation($user, $team->getKey(), 'conv b', $conversationB);

    $resolveInterface = ChatBrowser::resolveInterface();

    $page = ChatBrowser::logIn($user, $team->slug, $conversationA)
        ->assertSourceHas('placeholder="Ask anything..."');

    $leaked = $page->script(<<<JS
        (() => {
            {$resolveInterface}

            // Exactly the state switchConversation() leaves behind on a cache hit:
            // the transcript now shows B, the subscription is still A's.
            data.messages = [];
            data.conversationId = '{$conversationB}';
            data.channelConversationId = '{$conversationA}';

            // A's turn finishes and broadcasts on A's channel, which this tab
            // is still listening to.
            data.handleStreamStart({ invocation_id: 'inv-a' });
            data.handleTextDelta({ invocation_id: 'inv-a', delta: 'LEAKED_FROM_CONVERSATION_A' });
            data.handleToolCall({ invocation_id: 'inv-a', tool_name: 'list-companies-tool' });

            return {
                transcript: data.messages.map((m) => m.content ?? '').join(' '),
                streaming: data.isStreaming,
                toolStatus: data.currentToolStatus,
            };
        })();
    JS);

    expect($leaked['transcript'])->not->toContain('LEAKED_FROM_CONVERSATION_A')
        ->and($leaked['transcript'])->toBe('')
        ->and($leaked['streaming'])->toBeFalse()
        ->and($leaked['toolStatus'])->toBeNull();
});
