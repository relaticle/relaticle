<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;

mutates(ChatInterface::class);

/**
 * Drafts persist to localStorage under `chat.draft.{conversationId}` (the
 * literal segment `new` for a composer that has not created a conversation
 * yet), debounced 400ms after the TipTap document changes.
 *
 * Two timing traps, both matching SendStateTest's own conventions:
 *
 * - Typing uses `type()` (a single fill() action), not MentionPickerTest's
 *   char-by-char `keys()`: AwaitableWebpage retries any single method call
 *   that does not settle within its ~1s per-attempt budget, and a retried
 *   multi-keystroke `keys()` call would replay every keystroke, not resume
 *   it. This file has no need for per-keystroke timing (no suggestion popup
 *   to drive), so a single fill() sidesteps the risk entirely.
 * - sendMessage() is fired WITHOUT awaiting it inside a script() call, then
 *   polled for with independent short reads. Awaiting a real round trip
 *   (conversation create + channel subscribe + send) inside a single
 *   script() call hits the exact same per-attempt budget: confirmed live
 *   that an awaited call gets silently abandoned mid-flight and the
 *   optimistic bubble never leaves 'sending', even though the identical
 *   request succeeds in under a second when driven directly. Firing and
 *   polling avoids ever waiting for a slow operation through that bridge.
 */
it('restores a typed draft into the composer after a reload', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'draft test', $conversationId);

    $editor = '[data-chat-context="conversation"] [contenteditable="true"]';
    $editorJson = json_encode($editor);
    $draftKeyJson = json_encode("chat.draft.{$conversationId}");

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click($editor)->type($editor, 'draft survives reload');

    $page->assertScript("(() => localStorage.getItem({$draftKeyJson}) !== null)()");

    $page->refresh()->assertSourceHas('placeholder="Ask anything..."');

    $page->assertScript("(() => document.querySelector({$editorJson})?.textContent.trim() ?? null)()", 'draft survives reload');
});

it('keeps drafts scoped per conversation when switching between chats', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat a', $conversationA);
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat b', $conversationB);

    $editor = '[data-chat-context="conversation"] [contenteditable="true"]';
    $editorJson = json_encode($editor);
    $draftAKeyJson = json_encode("chat.draft.{$conversationA}");

    $page = ChatBrowser::logIn($user, $team->slug, $conversationA)
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click($editor)->type($editor, 'draft for chat a');

    $page->assertScript("(() => localStorage.getItem({$draftAKeyJson}) !== null)()");

    $page->navigate("/app/{$team->slug}/chats/{$conversationB}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->assertScript("(() => document.querySelector({$editorJson})?.textContent.trim() ?? null)()", '');

    $page->navigate("/app/{$team->slug}/chats/{$conversationA}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->assertScript("(() => document.querySelector({$editorJson})?.textContent.trim() ?? null)()", 'draft for chat a');
});

/**
 * Regression test for a leaked saveDraft() debounce timer surviving a real
 * SPA transition (Livewire's wire:navigate, not a hard page load).
 *
 * $page->navigate() in the tests above is Playwright's page.goto(): a hard
 * reload that tears down and rebuilds the whole JS realm, which trivially
 * prevents any leaked timer regardless of whether destroy() cleans it up.
 * That never exercises the path real users take, and where the bug lived:
 * clicking a different chat in the sidebar (wire:navigate) destroys the old
 * chatInterface instance in place while the page itself stays alive. Before
 * destroy() cleared draftDebounceTimer, a fragment typed in A with the timer
 * still pending when the user clicked away would fire ~400ms later bound to
 * the DEAD A instance. That callback calls localEditor(), which resolves by
 * data-chat-context STRING rather than by DOM subtree, so it found the NEW
 * live B instance mounted under the same "conversation" context, read B's
 * private composer content, and saved it under chat.draft.<A>: the id
 * survives on the dead instance's `this.conversationId`, the CONTENT read is
 * whichever instance the global query happens to resolve to.
 */
it('does not leak a pending draft timer across a real SPA conversation switch', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat a', $conversationA);
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat b', $conversationB);

    $editor = '[data-chat-context="conversation"] [contenteditable="true"]';
    $draftAKeyJson = json_encode("chat.draft.{$conversationA}");
    $draftBKeyJson = json_encode("chat.draft.{$conversationB}");

    $page = ChatBrowser::logIn($user, $team->slug, $conversationA)
        ->assertSourceHas('placeholder="Ask anything..."');

    // Type in A, then switch to B via the real sidebar wire:navigate link
    // with no deliberate wait in between: landing well inside the 400ms
    // debounce window is the whole point of this repro.
    $page->click($editor)->type($editor, 'fragment from conversation a');
    // Scoped to the persistent sidebar nav: an unscoped href match also hits
    // the topbar's "recent chats" menu link for the same conversation.
    $page->click("nav[aria-label=\"Sidebar navigation\"] a[href*=\"{$conversationB}\"]")
        ->assertPathIs("/app/{$team->slug}/chats/{$conversationB}");

    // A fresh, distinct fragment in B: this is the exact content a leaked
    // A-bound timer would read (via localEditor()'s global-by-context
    // resolution) and misfile under chat.draft.<A>.
    $page->click($editor)->type($editor, 'fragment from conversation b');

    // Give both B's own legitimate debounce AND any leaked A-bound timer
    // (pre-fix) time to fire.
    $page->script(<<<'JS'
        (() => new Promise((resolve) => setTimeout(resolve, 800)))();
    JS);

    $draftA = $page->script(<<<JS
        (() => localStorage.getItem({$draftAKeyJson}))();
    JS);
    $draftB = $page->script(<<<JS
        (() => localStorage.getItem({$draftBKeyJson}))();
    JS);

    // The assertion that matters: A's draft key must never carry B's private
    // text. Post-fix, A's pending timer is cancelled at destroy() before it
    // ever fires, so this key is typically absent (null): but the check
    // must hold either way, since A's own debounce firing just before the
    // switch (legitimately saving A's own fragment) is also a valid outcome.
    $draftALeakedBContent = $draftA !== null && str_contains($draftA, 'fragment from conversation b');
    expect($draftALeakedBContent)->toBeFalse();

    expect($draftB)->not->toBeNull();
    expect(str_contains((string) $draftB, 'fragment from conversation b'))->toBeTrue();
});

it('clears the draft once the message is actually sent', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'draft test', $conversationId);

    $editorJson = json_encode('[data-chat-context="conversation"] [contenteditable="true"]');
    $draftKeyJson = json_encode("chat.draft.{$conversationId}");

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.localEditor().setText('hello, then cleared');
            return true;
        })();
    JS);

    $page->assertScript("(() => localStorage.getItem({$draftKeyJson}) !== null)()");

    $page->script(<<<JS
        (() => {
            {$resolveInterface}
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

    $afterSend = $page->script(<<<JS
        (() => localStorage.getItem({$draftKeyJson}))();
    JS);
    expect($afterSend)->toBeNull();

    $page->refresh()->assertSourceHas('placeholder="Ask anything..."');

    $page->assertScript("(() => document.querySelector({$editorJson})?.textContent.trim() ?? null)()", '');
});

it('clears the new-conversation draft bucket once the first message creates the conversation', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = ChatBrowser::logIn($user, $team->slug)
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    // The composer works before any conversation exists (conversationId is
    // still null here): typing must bucket the draft under the literal
    // 'new' key segment, not a resolved id.
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.localEditor().setText('first message ever');
            return data.conversationId;
        })();
    JS);

    $page->assertScript("(() => localStorage.getItem('chat.draft.new') !== null)()");

    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.sendMessage();
            return true;
        })();
    JS);

    // Poll all the way to the terminal 'sent' state before inspecting
    // localStorage: conversationId is assigned midway through deliverMessage,
    // well before clearDraft() runs at the very end, so a shallower poll on
    // conversationId alone would race the assertion below against the rest
    // of that async function.
    $page->assertScript(<<<JS
        (() => {
            {$resolveInterface}
            return data.messages.find((m) => m.role === 'user')?.sendState ?? null;
        })()
    JS, 'sent');

    $newConversationId = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            return data.conversationId;
        })();
    JS);
    expect($newConversationId)->not->toBeNull();

    // The 'new' bucket that funded this exact send must not survive it: a
    // later fresh "new chat" composer would otherwise restore a message
    // that was already sent and now lives in a real conversation.
    $leftoverNewDraft = $page->script(<<<'JS'
        (() => localStorage.getItem('chat.draft.new'))();
    JS);
    expect($leftoverNewDraft)->toBeNull();
});
