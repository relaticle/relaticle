<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
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
 *
 * chatInterfaceInsertConversation() below intentionally duplicates the tiny
 * insert helper already private to ConversationSwitchTest.php/ComposerDraftTest.php
 * rather than reusing theirs: PHP function names are global across the whole
 * suite, so this file needs its own uniquely-prefixed name to avoid a
 * redeclaration fatal when Pest loads every test file into one process.
 */
function chatInterfaceInsertConversation(string $id, User $user, int|string $team, string $title): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team,
        'title' => $title,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('does not write a queued document from conversation A into conversation B\'s live composer', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    chatInterfaceInsertConversation($conversationA, $user, $team->getKey(), 'conv a');
    chatInterfaceInsertConversation($conversationB, $user, $team->getKey(), 'conv b');

    $editor = '[data-chat-context="conversation"] [contenteditable="true"]';
    $resolveInterface = ChatBrowser::resolveInterface();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationA}")
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
    chatInterfaceInsertConversation($conversationId, $user, $team->getKey(), 'conv single');

    $resolveInterface = ChatBrowser::resolveInterface();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
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
