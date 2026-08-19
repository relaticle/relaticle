<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);

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
function switchTestInsertConversation(string $id, User $user, int|string $team, string $title): void
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
    $result = ['paint' => null, 'navigatedAt' => null];

    for ($i = 0; $i < 40; $i++) {
        /** @var array{paint: array{at: float, conversationId: ?string, text: ?string}|null, navigatedAt: float|null} $state */
        $state = $page->script(<<<'JS'
            (() => ({ paint: window.__task7Paint ?? null, navigatedAt: window.__task7Navigated ?? null }))();
        JS);
        $result = $state;
        if ($result['paint'] !== null && $result['navigatedAt'] !== null) {
            return $result;
        }
        usleep(100_000);
    }

    return $result;
}

it('paints a previously visited conversation instantly, strictly before the real navigation swap', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    switchTestInsertConversation($conversationA, $user, $team->getKey(), 'chat a');
    switchTestInsertConversation($conversationB, $user, $team->getKey(), 'chat b');
    switchTestInsertMessage($conversationA, $user, 'user', 'Alpha seed message');
    switchTestInsertMessage($conversationB, $user, 'user', 'Bravo seed message');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationA}")
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
    switchTestInsertConversation($conversationA, $user, $team->getKey(), 'chat a');
    switchTestInsertConversation($conversationB, $user, $team->getKey(), 'chat b');
    switchTestInsertMessage($conversationA, $user, 'user', 'Alpha original message');
    switchTestInsertMessage($conversationB, $user, 'user', 'Bravo original message');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationA}")
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
    switchTestInsertConversation($conversationA, $user, $team->getKey(), 'chat a');
    switchTestInsertConversation($conversationB, $user, $team->getKey(), 'chat b');
    switchTestInsertMessage($conversationA, $user, 'user', 'Alpha real message');
    switchTestInsertMessage($conversationB, $user, 'user', 'Bravo real message');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationA}")
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
                copiedAt: 0,
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
