<?php

declare(strict_types=1);

use App\Filament\Pages\ChatConversation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);
mutates(ChatConversation::class);

it('places the conversation title in the topbar and the toggle beside the workspace menu', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'Pipeline review', $conversationId);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertVisible('[data-page-heading]')
        ->assertMissing('main h1')
        ->assertNoJavaScriptErrors();

    $page->script("window.Alpine.store('sidebar').open()");
    $page->wait(0.3);
    $page->assertScript('(() => document.querySelector("#fi-main-sidebar").classList.contains("fi-sidebar-open"))()');

    $chrome = $page->script(<<<'JS'
        (() => {
            const sidebar = document.querySelector('#fi-main-sidebar');
            const workspace = sidebar.querySelector('.fi-tenant-menu');
            const toggle = sidebar.querySelector('[data-sidebar-workspace-toggle]');
            const title = document.querySelector('[data-page-heading]');
            const workspaceRect = workspace.getBoundingClientRect();
            const toggleRect = toggle.getBoundingClientRect();
            const titleRect = title.getBoundingClientRect();
            const sidebarRect = sidebar.getBoundingClientRect();
            const topbarRect = document.querySelector('.fi-topbar').getBoundingClientRect();
            const chatSurfaceRect = document.querySelector('[data-chat-context="conversation"]').getBoundingClientRect();
            const composerRect = document.querySelector('[data-chat-composer]').getBoundingClientRect();

            return {
                title: title.textContent.trim(),
                titleInTopbar: !!title.closest('nav[aria-label=Topbar]'),
                toggleInSidebar: !!toggle.closest('#fi-main-sidebar'),
                toggleAfterWorkspace: toggleRect.left >= workspaceRect.right,
                titleAfterSidebar: titleRect.left >= sidebarRect.right,
                oldDesktopToggleHidden: getComputedStyle(document.querySelector('.fi-topbar-collapse-sidebar-btn-ctn')).display === 'none',
                chromeCentersAligned: Math.abs(workspaceRect.top + workspaceRect.height / 2 - titleRect.top - titleRect.height / 2) <= 0.5
                    && Math.abs(workspaceRect.top + workspaceRect.height / 2 - toggleRect.top - toggleRect.height / 2) <= 0.5,
                composerInsideViewport: composerRect.bottom <= window.innerHeight,
                headingOnlyHeaderRemovedFromFlow: getComputedStyle(document.querySelector('.fi-header-page-heading-only')).display === 'contents',
                topbarHeight: topbarRect.height,
                chatSurfaceTopGap: chatSurfaceRect.top - topbarRect.bottom,
                workspaceToggleGap: toggleRect.left - workspaceRect.right,
            };
        })();
    JS);

    expect($chrome)->toMatchArray([
        'title' => 'Pipeline review',
        'titleInTopbar' => true,
        'toggleInSidebar' => true,
        'toggleAfterWorkspace' => true,
        'titleAfterSidebar' => true,
        'oldDesktopToggleHidden' => true,
        'chromeCentersAligned' => true,
        'composerInsideViewport' => true,
        'headingOnlyHeaderRemovedFromFlow' => true,
        'topbarHeight' => 64,
        'chatSurfaceTopGap' => 0,
    ]);

    expect($chrome['workspaceToggleGap'])
        ->toBeGreaterThanOrEqual(4)
        ->toBeLessThanOrEqual(8);

    $page->click('[data-sidebar-workspace-toggle]')
        ->assertScript('(() => !document.querySelector("#fi-main-sidebar").classList.contains("fi-sidebar-open"))()')
        ->assertScript(<<<'JS'
            (() => {
                const workspace = document.querySelector('#fi-main-sidebar > .fi-tenant-menu');
                const toggle = document.querySelector('[data-sidebar-workspace-toggle]');
                const workspaceRect = workspace.getBoundingClientRect();
                const toggleRect = toggle.getBoundingClientRect();
                const workspaceHit = document.elementFromPoint(
                    workspaceRect.left + workspaceRect.width / 2,
                    workspaceRect.top + workspaceRect.height / 2,
                );
                const toggleHit = document.elementFromPoint(
                    toggleRect.left + toggleRect.width / 2,
                    toggleRect.top + toggleRect.height / 2,
                );

                return workspaceRect.right <= toggleRect.left
                    && workspaceRect.width === 32
                    && toggleRect.width === 32
                    && Math.abs(workspaceRect.top + workspaceRect.height / 2 - toggleRect.top - toggleRect.height / 2) <= 0.5
                    && !!workspaceHit.closest('.fi-tenant-menu')
                    && !!toggleHit.closest('[data-sidebar-workspace-toggle]');
            })()
            JS)
        ->click('[data-sidebar-workspace-toggle]')
        ->assertScript('(() => document.querySelector("#fi-main-sidebar").classList.contains("fi-sidebar-open"))()')
        ->resize(390, 844)
        ->assertVisible('[data-page-heading]')
        ->assertScript('(() => Array.from(document.querySelectorAll("nav[aria-label=Topbar] button[aria-label^=Expand]")).filter((button) => button.getClientRects().length > 0).length === 1)()')
        ->assertScript('(() => getComputedStyle(document.querySelector("[data-sidebar-workspace-toggle]")).display === "none")()');

    $page->script(<<<JS
        (() => window.dispatchEvent(new CustomEvent('chat:renamed', {
            detail: { conversationId: '{$conversationId}', title: 'Updated pipeline review' },
        })))();
    JS);

    $page->assertSeeIn('[data-page-heading]', 'Updated pipeline review');
});

/**
 * Cache-first conversation switching: a wire:navigate sidebar link destroys
 * and remounts the `chatInterface` Alpine component (confirmed empirically,
 * see the header comment above conversationCacheEntries() in transcript.js),
 * so the cache lives on `window`, not on `this`. Switching to a conversation
 * that was visited earlier in the same tab paints its last-known transcript
 * before the wire:navigate round trip resolves; the round trip itself is
 * what reconciles that paint against server truth, wholesale.
 *
 * "Instant" is proven with real event timestamps, not a blind read-after-
 * click: this app's local dev server (an in-process PHP built-in server via
 * pest-plugin-browser, tiny fixture, no real network) is fast enough that a
 * single `script()` read taken right after `click()` showed the destination
 * conversation's REAL content even with the cache-paint code completely
 * reverted: the genuine round trip had simply already finished by the time
 * that read executed. A blind "immediately after click" read is therefore
 * not evidence of anything; it passes with or without this feature. Instead,
 * production code dispatches a `chat:cache-painted` CustomEvent the instant
 * it paints from cache (see switchConversation() in transcript.js), and each
 * test below compares that event's performance.now() timestamp against
 * Livewire's own `livewire:navigated` event (fired only once the real
 * round trip's content has been swapped in). If the cache paint did not
 * happen, `chat:cache-painted` never fires and the test fails outright
 * (confirmed: reverting the implementation while keeping this file made
 * exactly that assertion fail, not any timing assertion), so this is
 * failure-of-absence, not a race that luck can win.
 */
function switchTestInsertMessage(string $conversationId, User $user, string $role, string $content, ?Carbon $at = null): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => $role,
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => $at ?? now(),
        'updated_at' => $at ?? now(),
    ]);
}

/**
 * Arms two one-shot listeners: `chat:cache-painted` captures a
 * performance.now() timestamp plus the transcript's live text content at
 * that exact instant; `livewire:navigated` captures a timestamp for when
 * the real round trip actually swapped the page. Must be (re-)armed right
 * before each click: both are `{ once: true }` so a later click's results
 * are never confused with an earlier one's.
 */
function switchTestArmSwitchProbe(AwaitableWebpage $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__task7Paint = null;
            window.__task7Navigated = null;
            window.addEventListener('chat:cache-painted', (e) => {
                if (window.__task7Paint) return;
                const el = document.querySelector('[data-chat-context="conversation"] [role="log"]');
                window.__task7Paint = {
                    at: performance.now(),
                    conversationId: e.detail?.conversationId ?? null,
                    text: el ? el.textContent : null,
                };
            }, { once: true });
            document.addEventListener('livewire:navigated', () => {
                if (window.__task7Navigated) return;
                window.__task7Navigated = performance.now();
            }, { once: true });
            return true;
        })();
    JS);
}

/**
 * @return array{paint: array{at: float, conversationId: ?string, text: ?string}|null, navigatedAt: float|null}
 */
function switchTestReadSwitchProbe(AwaitableWebpage $page): array
{
    $page->assertScript('(() => !!(window.__task7Paint && window.__task7Navigated))()');

    /** @var array{paint: array{at: float, conversationId: ?string, text: ?string}|null, navigatedAt: float|null} $state */
    $state = $page->script(<<<'JS'
        (() => ({ paint: window.__task7Paint ?? null, navigatedAt: window.__task7Navigated ?? null }))();
    JS);

    return $state;
}

it('paints a previously visited conversation instantly, strictly before the real navigation swap', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat a', $conversationA);
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat b', $conversationB);
    switchTestInsertMessage($conversationA, $user, 'user', 'Alpha seed message');
    switchTestInsertMessage($conversationB, $user, 'user', 'Bravo seed message');

    $page = ChatBrowser::logIn($user, $team->slug, $conversationA)
        ->assertSourceHas('Alpha seed message');

    // A -> B is a normal (uncached) transition: it is what causes A's
    // outgoing destroy() to stash A's transcript into the window cache.
    $page->click("nav[aria-label=\"Sidebar navigation\"] a[href*=\"{$conversationB}\"]")
        ->assertPathIs("/app/{$team->slug}/chats/{$conversationB}")
        ->assertSourceHas('Bravo seed message');

    switchTestArmSwitchProbe($page);
    $page->click("nav[aria-label=\"Sidebar navigation\"] a[href*=\"{$conversationA}\"]");
    $probe = switchTestReadSwitchProbe($page);

    expect($probe['paint'])->not->toBeNull();
    expect($probe['paint']['conversationId'])->toBe($conversationA);
    expect($probe['paint']['text'])->toContain('Alpha seed message');
    expect($probe['navigatedAt'])->not->toBeNull();
    expect($probe['paint']['at'])->toBeLessThan($probe['navigatedAt']);

    $page->assertPathIs("/app/{$team->slug}/chats/{$conversationA}")
        ->assertSourceHas('Alpha seed message');
});

it('reconciles a cached (stale) paint against server truth once the real switch completes', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat a', $conversationA);
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat b', $conversationB);
    switchTestInsertMessage($conversationA, $user, 'user', 'Alpha original message');
    switchTestInsertMessage($conversationB, $user, 'user', 'Bravo original message');

    $page = ChatBrowser::logIn($user, $team->slug, $conversationA)
        ->assertSourceHas('Alpha original message');

    // A's cache entry is now stashed (its destroy() just ran as part of
    // this switch).
    $page->click("nav[aria-label=\"Sidebar navigation\"] a[href*=\"{$conversationB}\"]")
        ->assertPathIs("/app/{$team->slug}/chats/{$conversationB}")
        ->assertSourceHas('Bravo original message');

    // Simulates a second session (or another user) sending into A while
    // this tab has A closed and B open: the cached copy of A cannot know
    // about this.
    switchTestInsertMessage($conversationA, $user, 'assistant', 'Alpha message inserted while B was open', now()->addSecond());

    switchTestArmSwitchProbe($page);
    $page->click("nav[aria-label=\"Sidebar navigation\"] a[href*=\"{$conversationA}\"]");
    $probe = switchTestReadSwitchProbe($page);

    // The cache paint genuinely fired and genuinely showed the STALE
    // transcript (the message inserted a moment ago was not in it); this
    // is what makes the assertion below a real test of reconciliation
    // rather than of the page simply having loaded correctly on its own.
    expect($probe['paint'])->not->toBeNull();
    expect($probe['paint']['text'])->toContain('Alpha original message');
    expect($probe['paint']['text'])->not->toContain('Alpha message inserted while B was open');

    // The real navigation that follows always finishes with authoritative
    // content, wholesale-replacing the stale paint above.
    $page->assertPathIs("/app/{$team->slug}/chats/{$conversationA}")
        ->assertSourceHas('Alpha message inserted while B was open');
});

it('never paints a transcript cached under a different user', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat a', $conversationA);
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat b', $conversationB);
    switchTestInsertMessage($conversationA, $user, 'user', 'Alpha real message');
    switchTestInsertMessage($conversationB, $user, 'user', 'Bravo real message');

    $page = ChatBrowser::logIn($user, $team->slug, $conversationA)
        ->assertSourceHas('Alpha real message');

    $page->click("nav[aria-label=\"Sidebar navigation\"] a[href*=\"{$conversationB}\"]")
        ->assertPathIs("/app/{$team->slug}/chats/{$conversationB}")
        ->assertSourceHas('Bravo real message');

    // Tamper the window cache to simulate what a leak from a different
    // user's session would look like: a mismatched owner tag, with a
    // spoofed entry for A's conversation id carrying content this user
    // never saw. A cache that keyed purely on conversation id (ignoring
    // ownership) would paint this instantly on the very next switch.
    $conversationAJson = json_encode($conversationA);
    $tampered = $page->script(<<<JS
        (() => {
            const current = window.__chatConversationCache;
            if (!current) return false;
            const entries = new Map(current.entries);
            entries.set({$conversationAJson}, [{
                role: 'assistant',
                content: 'LEAKED CONTENT FROM ANOTHER USER',
                clientKey: 'spoofed-1',
                rendered: true,
                prerendered: true,
                pending_actions: [],
                follow_ups: [],
                feedback: null,
            }]);
            window.__chatConversationCache = { owner: 'someone-else-entirely', entries };
            return true;
        })();
    JS);
    expect($tampered)->toBeTrue();

    // A MutationObserver on the transcript, armed before the switch, is the
    // only way to reliably prove the leaked text never rendered even
    // transiently: a single read after the fact races the real navigation
    // exactly like the naive version of the earlier tests did.
    $page->script(<<<'JS'
        (() => {
            window.__task7LeakSeen = false;
            const target = document.querySelector('[data-chat-context="conversation"] [role="log"]');
            if (!target) return false;
            const observer = new MutationObserver(() => {
                if (target.textContent && target.textContent.includes('LEAKED CONTENT FROM ANOTHER USER')) {
                    window.__task7LeakSeen = true;
                }
            });
            observer.observe(target, { childList: true, subtree: true, characterData: true });
            return true;
        })();
    JS);

    $page->click("nav[aria-label=\"Sidebar navigation\"] a[href*=\"{$conversationA}\"]")
        ->assertPathIs("/app/{$team->slug}/chats/{$conversationA}")
        ->assertSourceHas('Alpha real message');

    $leakSeen = $page->script(<<<'JS'
        (() => window.__task7LeakSeen === true)();
    JS);
    expect($leakSeen)->toBeFalse();

    // The mismatched owner also means the cache was reset outright: nothing
    // is left over under the fake owner for a later legitimate switch to
    // trip on.
    $ownerAfter = $page->script(<<<'JS'
        (() => window.__chatConversationCache?.owner ?? null)();
    JS);
    expect($ownerAfter)->not->toBe('someone-else-entirely');
});

it('caps the conversation cache at 5 entries, evicting the least recently used', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationSeed = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'chat seed', $conversationSeed);
    switchTestInsertMessage($conversationSeed, $user, 'user', 'Seed message');

    $page = ChatBrowser::logIn($user, $team->slug, $conversationSeed)
        ->assertSourceHas('Seed message');

    $resolveInterface = ChatBrowser::resolveInterface();

    // The cache is pure client-side Map bookkeeping (see
    // conversationCacheEntries() / cacheConversationMessages() in
    // transcript.js): stashConversationCache() only reads whatever is
    // currently on `this.conversationId` / `this.messages`, so driving it
    // through 6 distinct ids needs no real conversation rows or wire:navigate
    // round trips per id, only the one real mount above to get a live
    // `chatInterface` instance to call it on.
    $afterSixthStash = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            const ids = ['lru-1', 'lru-2', 'lru-3', 'lru-4', 'lru-5', 'lru-6'];
            for (const id of ids) {
                data.conversationId = id;
                data.messages = [{ role: 'user', content: 'msg for ' + id, clientKey: id }];
                data.stashConversationCache();
            }
            const cache = window.__chatConversationCache;
            return { size: cache.entries.size, keys: Array.from(cache.entries.keys()) };
        })();
    JS);

    // A 6th entry evicts the least recently used (lru-1, cached first and
    // never touched again), not merely the "first" one by some other rule.
    expect($afterSixthStash['size'])->toBe(5);
    expect($afterSixthStash['keys'])->toBe(['lru-2', 'lru-3', 'lru-4', 'lru-5', 'lru-6']);

    // lru-2 is now the oldest surviving entry. Read it back through
    // switchConversation() (the exact path a real switch takes) so its
    // recency bumps, then stash one more conversation: this is the same
    // stash-before-overwrite switchConversation() already does for the
    // outgoing conversation on every real switch, so it doubles as the "7th
    // write" that forces another eviction. If the recency bump is a no-op,
    // lru-2 (inserted before lru-3/4/5/6) is the next one evicted; if the
    // bump works, lru-3 (never re-read) is evicted instead and lru-2
    // survives despite being the chronologically older entry.
    $afterRecencyBump = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.conversationId = 'scratch-outgoing';
            data.messages = [{ role: 'user', content: 'scratch', clientKey: 'scratch-outgoing' }];
            const switched = data.switchConversation('lru-2');
            const cache = window.__chatConversationCache;
            return { switched, keys: Array.from(cache.entries.keys()) };
        })();
    JS);

    expect($afterRecencyBump['switched'])->toBeTrue();
    expect($afterRecencyBump['keys'])->toHaveCount(5);
    expect($afterRecencyBump['keys'])->toContain('lru-2');
    expect($afterRecencyBump['keys'])->not->toContain('lru-3');
});
