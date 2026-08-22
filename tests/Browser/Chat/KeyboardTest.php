<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);

/**
 * Cmd+O / Ctrl+O opens a full-page conversation switcher (Cmd+K is taken by
 * Filament's global search app-wide, Cmd+J by the chat side-panel toggle
 * app-wide, both confirmed live before picking a third key). Esc closes it.
 * Cmd+F / Ctrl+F opens in-conversation search; it is taken over only inside
 * the chat subtree, so the browser's own find still works elsewhere.
 * ArrowUp in an empty composer enters edit mode on the last user message.
 * All of these bindings are wired via `x-on:keydown` on the chat root element
 * (not window/document), so a real keypress must originate from inside the
 * chat interface's own DOM subtree. These tests drive it through the real
 * composer, not by poking Alpine internals, so a regression in the actual
 * event-bubbling scope would fail them too.
 */
const KEYBOARD_TEST_EDITOR = '[data-chat-context="conversation"] [contenteditable="true"]';
const KEYBOARD_TEST_SWITCHER = '[role="dialog"][aria-label="Switch conversation"]';
const KEYBOARD_TEST_MESSAGE_SEARCH = '[role="dialog"][aria-label="Search this conversation"]';

function keyboardTestInsertConversation(string $id, User $user, int|string $team, string $title): void
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

function keyboardTestInsertMessage(string $conversationId, User $user, string $role, string $content): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'agent' => CrmAssistant::class,
        'role' => $role,
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('opens the switcher on Cmd/Ctrl+O, filters as you type, and Enter navigates to the highlighted chat', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    $conversationB = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationA, $user, $team->getKey(), 'Alpha planning thread');
    keyboardTestInsertConversation($conversationB, $user, $team->getKey(), 'Bravo onboarding notes');
    keyboardTestInsertMessage($conversationA, $user, 'user', 'Alpha seed message');
    keyboardTestInsertMessage($conversationB, $user, 'user', 'Bravo seed message');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationA}")
        ->assertSourceHas('Alpha seed message');

    $page->click(KEYBOARD_TEST_EDITOR)->keys(KEYBOARD_TEST_EDITOR, 'Control+o');
    $page->assertVisible(KEYBOARD_TEST_SWITCHER);
    $page->assertSeeIn(KEYBOARD_TEST_SWITCHER, 'Alpha planning thread');
    $page->assertSeeIn(KEYBOARD_TEST_SWITCHER, 'Bravo onboarding notes');

    $page->type(KEYBOARD_TEST_SWITCHER.' input[type="search"]', 'Bravo');
    $page->assertSeeIn(KEYBOARD_TEST_SWITCHER, 'Bravo onboarding notes');
    // Scoped to the dialog only: the sidebar nav lists every recent chat
    // (including "Alpha planning thread") regardless of the switcher's own
    // filter, so a page-wide assertDontSee would be a false positive here.
    $page->assertDontSeeIn(KEYBOARD_TEST_SWITCHER, 'Alpha planning thread');

    $page->keys(KEYBOARD_TEST_SWITCHER.' input[type="search"]', 'Enter');

    $page->assertPathIs("/app/{$team->slug}/chats/{$conversationB}")
        ->assertSourceHas('Bravo seed message');
    $page->assertMissing(KEYBOARD_TEST_SWITCHER);
});

it('closes the switcher on Esc without navigating', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationA = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationA, $user, $team->getKey(), 'Alpha planning thread');
    keyboardTestInsertMessage($conversationA, $user, 'user', 'Alpha seed message');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationA}")
        ->assertSourceHas('Alpha seed message');

    $page->click(KEYBOARD_TEST_EDITOR)->keys(KEYBOARD_TEST_EDITOR, 'Control+o');
    $page->assertVisible(KEYBOARD_TEST_SWITCHER);

    $page->keys(KEYBOARD_TEST_SWITCHER.' input[type="search"]', 'Escape');

    $page->assertMissing(KEYBOARD_TEST_SWITCHER);
    $page->assertPathIs("/app/{$team->slug}/chats/{$conversationA}");
});

it('enters edit mode on the last user message when ArrowUp is pressed in an empty composer', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationId, $user, $team->getKey(), 'Arrow up chat');
    keyboardTestInsertMessage($conversationId, $user, 'user', 'my original message');
    keyboardTestInsertMessage($conversationId, $user, 'assistant', 'an assistant reply');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('my original message');

    $page->click(KEYBOARD_TEST_EDITOR)->keys(KEYBOARD_TEST_EDITOR, 'ArrowUp');

    $page->assertVisible('textarea[aria-label="Edit message"]');
    $page->assertValue('textarea[aria-label="Edit message"]', 'my original message');
});

it('does not enter edit mode when the composer has unsent text', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationId, $user, $team->getKey(), 'Arrow up guarded chat');
    keyboardTestInsertMessage($conversationId, $user, 'user', 'my original message');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('my original message');

    $page->click(KEYBOARD_TEST_EDITOR)->type(KEYBOARD_TEST_EDITOR, 'a draft in progress');
    $page->keys(KEYBOARD_TEST_EDITOR, 'ArrowUp');

    $page->assertMissing('textarea[aria-label="Edit message"]');
});

/**
 * Cmd+F / Ctrl+F searches inside the open conversation. The point of the
 * feature is a hit that is NOT on screen: the transcript renders one page of
 * 50, so picking a hit older than that has to walk the pager backwards until
 * the message exists before it can be scrolled to. This drives the whole path
 * through the real composer and the real overlay, so a regression anywhere
 * from the keydown scope to the load-until-found loop fails it.
 */
it('opens search on Cmd/Ctrl+F and pages back through history to reach a hit below the first page', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationId, $user, $team->getKey(), 'Deep history chat');

    $rows = [];

    // 110 messages puts the target two full pages below the newest 50.
    foreach (range(1, 110) as $i) {
        $rows[] = [
            'id' => sprintf('ks-%03d', $i),
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'agent' => CrmAssistant::class,
            'role' => $i % 2 === 1 ? 'user' : 'assistant',
            'content' => $i === 2 ? 'the Northwind renewal' : "filler line {$i}",
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->subMinutes(200 - $i),
            'updated_at' => now()->subMinutes(200 - $i),
        ];
    }

    DB::table('agent_conversation_messages')->insert($rows);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('filler line 110');

    $page->assertMissing('[data-message-id="ks-002"]');

    $page->click(KEYBOARD_TEST_EDITOR)->keys(KEYBOARD_TEST_EDITOR, 'Control+f');
    $page->assertVisible(KEYBOARD_TEST_MESSAGE_SEARCH);

    $page->type(KEYBOARD_TEST_MESSAGE_SEARCH.' input[type="search"]', 'northwind');
    $page->assertSeeIn(KEYBOARD_TEST_MESSAGE_SEARCH, 'the Northwind renewal');

    $page->keys(KEYBOARD_TEST_MESSAGE_SEARCH.' input[type="search"]', 'Enter');

    $page->assertMissing(KEYBOARD_TEST_MESSAGE_SEARCH);
    $page->assertVisible('[data-message-id="ks-002"]');

    // The overlay is hidden while the walk runs, so the focused search input is
    // display:none'd and focus falls to <body>, which is NOT inside the chat
    // root. The root's keydown layer only sees events from its own subtree, so
    // leaving focus there kills every chat shortcut until the user clicks back
    // in. jumpToMessage() hands focus to the composer; without that this reads
    // BODY and fails.
    $focusReturnedToChat = $page->script(<<<'JS'
        (async () => {
            const root = document.querySelector('[data-chat-context="conversation"]');

            for (let i = 0; i < 40; i++) {
                if (root.contains(document.activeElement)) return 1;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }

            return 0;
        })();
    JS);

    expect((int) $focusReturnedToChat)->toBe(1);
});

/**
 * jumpToMessage() dismisses the search overlay the instant it starts, so the
 * only element left inside the chat root that a real keypress can land on is
 * the composer; Esc there must still cancel the walk. Reaching this hit needs
 * 6 real loadEarlier() round trips (300 seeded messages, target 2 rows deep):
 * measured beforehand at ~1.6s to run to completion uncancelled, comfortably
 * inside this test's wait budget, so Escape sent immediately after Enter is
 * given every chance to land mid-walk rather than after it.
 */
it('cancels an in-flight search jump on Esc and stops the walk', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationId, $user, $team->getKey(), 'Very deep history chat');

    $total = 300;
    $rows = [];

    foreach (range(1, $total) as $i) {
        $rows[] = [
            'id' => sprintf('kc-%04d', $i),
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'agent' => CrmAssistant::class,
            'role' => $i % 2 === 1 ? 'user' : 'assistant',
            'content' => $i === 2 ? 'the zylophonic migration record' : "filler line {$i}",
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->subMinutes($total - $i + 1),
            'updated_at' => now()->subMinutes($total - $i + 1),
        ];
    }

    DB::table('agent_conversation_messages')->insert($rows);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas("filler line {$total}");

    $page->assertMissing('[data-message-id="kc-0002"]');

    $page->click(KEYBOARD_TEST_EDITOR)->keys(KEYBOARD_TEST_EDITOR, 'Control+f');
    $page->assertVisible(KEYBOARD_TEST_MESSAGE_SEARCH);

    $page->type(KEYBOARD_TEST_MESSAGE_SEARCH.' input[type="search"]', 'zylophonic');
    $page->assertSeeIn(KEYBOARD_TEST_MESSAGE_SEARCH, 'the zylophonic migration record');

    $page->keys(KEYBOARD_TEST_MESSAGE_SEARCH.' input[type="search"]', 'Enter');
    $page->keys(KEYBOARD_TEST_EDITOR, 'Escape');

    // The cancel flag is only checked once per loop iteration (by design: it
    // must not abort a page load already in flight), so the single round trip
    // that was already running when Escape landed is still allowed to finish.
    // This wait is that one grace page settling, not the proof itself.
    $page->wait(1);

    $loadedAfterGrace = (int) $page->script(<<<'JS'
        window.Alpine.$data(document.querySelector('[data-chat-context="conversation"]')).messages.length
    JS);

    // Left unfixed, the walk needs ~1.6s total to reach the target on its
    // own; this waits well past that so an uncancelled walk would already
    // have finished growing.
    $page->wait(3);

    $loadedAfterFurtherWait = (int) $page->script(<<<'JS'
        window.Alpine.$data(document.querySelector('[data-chat-context="conversation"]')).messages.length
    JS);

    // The proof the walk actually stopped, rather than merely not having
    // reached the target yet: no page loaded beyond that one grace round
    // trip. Left unfixed, the walk keeps paging in the background and this
    // count keeps growing well past both waits, until it reaches the target.
    expect($loadedAfterFurtherWait)->toBe($loadedAfterGrace);

    $page->assertMissing('[data-message-id="kc-0002"]');
    // A cancel is the user leaving, not a failure: it must not reopen the
    // overlay (with a stalled/unreachable notice or otherwise).
    $page->assertMissing(KEYBOARD_TEST_MESSAGE_SEARCH);
});

/**
 * The case above sends Escape via Playwright's press(), which focuses its
 * target element directly, bypassing the real focus path entirely. That is
 * not what a real keypress does: dismissMessageSearch() hides the search
 * input the instant the walk starts, and a display:none'd focused element
 * drops the browser's own focus to <body>, which sits outside the chat
 * root's x-on:keydown subtree. A keydown there could never bubble to the
 * handler, no matter what the handler's own guard checks. jumpToMessage()
 * must therefore move focus onto something still inside the root (the
 * composer) before Escape has any chance of being seen at all.
 *
 * This case never targets a selector for the keypress: it reads whatever
 * document.activeElement actually is and dispatches Escape there, exactly
 * as a real untargeted keypress would land.
 */
it('reaches Escape from the real post-Enter focus, not just a targeted keypress', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationId, $user, $team->getKey(), 'Untargeted escape chat');

    $total = 300;
    $rows = [];

    foreach (range(1, $total) as $i) {
        $rows[] = [
            'id' => sprintf('ke-%04d', $i),
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'agent' => CrmAssistant::class,
            'role' => $i % 2 === 1 ? 'user' : 'assistant',
            'content' => $i === 2 ? 'the flibbertigibbet quarterly digest' : "filler line {$i}",
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->subMinutes($total - $i + 1),
            'updated_at' => now()->subMinutes($total - $i + 1),
        ];
    }

    DB::table('agent_conversation_messages')->insert($rows);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas("filler line {$total}");

    $page->assertMissing('[data-message-id="ke-0002"]');

    $page->click(KEYBOARD_TEST_EDITOR)->keys(KEYBOARD_TEST_EDITOR, 'Control+f');
    $page->assertVisible(KEYBOARD_TEST_MESSAGE_SEARCH);

    $page->type(KEYBOARD_TEST_MESSAGE_SEARCH.' input[type="search"]', 'flibbertigibbet');
    $page->assertSeeIn(KEYBOARD_TEST_MESSAGE_SEARCH, 'the flibbertigibbet quarterly digest');

    $page->keys(KEYBOARD_TEST_MESSAGE_SEARCH.' input[type="search"]', 'Enter');

    // Waits for focus to actually settle off the (now hidden) search input,
    // then dispatches Escape on whatever document.activeElement really is,
    // never on a chosen selector. Measured beforehand: fixed code settles
    // onto the composer within ~5 animation frames; unfixed code settles
    // onto <body> just as fast and stays there.
    $landed = $page->script(<<<'JS'
        (async () => {
            const isSearchInput = (el) => !!el?.closest?.(
                '[role="dialog"][aria-label="Search this conversation"]'
            );
            let el = document.activeElement;
            for (let i = 0; i < 30 && isSearchInput(el); i++) {
                await new Promise((resolve) => requestAnimationFrame(resolve));
                el = document.activeElement;
            }
            const insideRoot = !!el?.closest?.('[data-chat-context="conversation"]');
            el.dispatchEvent(new KeyboardEvent('keydown', {
                key: 'Escape',
                bubbles: true,
                cancelable: true,
            }));
            return { tag: el?.tagName, isBody: el === document.body, insideRoot };
        })();
    JS);

    // The mechanism itself, not just its effect: with the fix, focus is
    // parked inside the root (the composer) before Escape is ever sent.
    expect($landed['insideRoot'])->toBeTrue();
    expect($landed['isBody'])->toBeFalse();

    $page->wait(1);

    $loadedAfterGrace = (int) $page->script(<<<'JS'
        window.Alpine.$data(document.querySelector('[data-chat-context="conversation"]')).messages.length
    JS);

    $page->wait(3);

    $loadedAfterFurtherWait = (int) $page->script(<<<'JS'
        window.Alpine.$data(document.querySelector('[data-chat-context="conversation"]')).messages.length
    JS);

    expect($loadedAfterFurtherWait)->toBe($loadedAfterGrace);

    $page->assertMissing('[data-message-id="ke-0002"]');
    $page->assertMissing(KEYBOARD_TEST_MESSAGE_SEARCH);
});

it('closes search on Esc without loading any history', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationId, $user, $team->getKey(), 'Escape search chat');
    keyboardTestInsertMessage($conversationId, $user, 'user', 'the only message');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('the only message');

    $page->click(KEYBOARD_TEST_EDITOR)->keys(KEYBOARD_TEST_EDITOR, 'Control+f');
    $page->assertVisible(KEYBOARD_TEST_MESSAGE_SEARCH);

    $page->keys(KEYBOARD_TEST_MESSAGE_SEARCH.' input[type="search"]', 'Escape');

    $page->assertMissing(KEYBOARD_TEST_MESSAGE_SEARCH);
    $page->assertPathIs("/app/{$team->slug}/chats/{$conversationId}");
});

/**
 * F3: toggling off a thumbs-down rating that already carries a category
 * discards real user-written detail, so it asks confirm() first. A plain
 * thumbs-up has no detail to lose and must never prompt.
 */
it('confirms before deleting a thumbs-down rating that has a saved category, and does nothing on cancel', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationId, $user, $team->getKey(), 'Feedback chat');
    keyboardTestInsertMessage($conversationId, $user, 'user', 'a question');
    keyboardTestInsertMessage($conversationId, $user, 'assistant', 'an unhelpful answer');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('an unhelpful answer');

    $page->click('button[aria-label="Bad response"]');
    $page->click('button:has-text("Inaccurate")');
    $page->click('button:has-text("Submit")');
    $page->assertAttribute('button[aria-label="Bad response"]', 'aria-pressed', 'true');

    // Cancel: window.confirm() returns false, nothing changes.
    $page->script('window.confirm = () => false;');
    $page->click('button[aria-label="Bad response"]');
    $page->assertAttribute('button[aria-label="Bad response"]', 'aria-pressed', 'true');

    // Confirm: window.confirm() returns true, the rating clears.
    $page->script('window.confirm = () => true;');
    $page->click('button[aria-label="Bad response"]');
    $page->assertAttribute('button[aria-label="Bad response"]', 'aria-pressed', 'false');
});

it('does not ask for confirmation when toggling off a thumbs-up rating', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    keyboardTestInsertConversation($conversationId, $user, $team->getKey(), 'Thumbs up chat');
    keyboardTestInsertMessage($conversationId, $user, 'user', 'a question');
    keyboardTestInsertMessage($conversationId, $user, 'assistant', 'a helpful answer');

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('a helpful answer');

    // Records every window.confirm() call instead of throwing on any call: Pest
    // Browser's own script()/evaluate() machinery makes an extra confirm(undefined)
    // probe call between commands (confirmed empirically, unrelated to app code),
    // so a throw-on-any-call assertion here is a false positive waiting to happen.
    // The real assertion is that the app's OWN confirmation text is never among
    // the recorded calls.
    $page->script('window.__confirmCalls = []; window.confirm = (m) => { window.__confirmCalls.push(m); return true; };');

    $page->click('button[aria-label="Good response"]');
    $page->assertAttribute('button[aria-label="Good response"]', 'aria-pressed', 'true');
    $page->click('button[aria-label="Good response"]');
    $page->assertAttribute('button[aria-label="Good response"]', 'aria-pressed', 'false');

    $calls = $page->script('window.__confirmCalls');
    expect($calls)->not->toContain('Remove this feedback? Your category and comment will be deleted.');
});
