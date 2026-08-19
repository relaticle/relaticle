<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);

/**
 * Message grouping + day separators: consecutive same-role messages under a
 * 3-minute gap (and no pending actions on the earlier one) render tightly
 * grouped (`data-grouped` on the later bubble); a calendar-day change between
 * two adjacent messages renders exactly one `data-day-separator` marker.
 */
function transcriptShapeInsertConversation(string $id, User $user, int|string $team): void
{
    DB::table('agent_conversations')->insert([
        'id' => $id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team,
        'title' => 'transcript shape',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function transcriptShapeInsertMessage(string $conversationId, User $user, string $content, Carbon $at): void
{
    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

/**
 * The initial HTML source carries the message payload inline (`@js($messages)`
 * inside the `x-data="chatInterface(...)"` attribute), so `assertSourceHas`
 * proves the server sent the data, not that Alpine's `x-for` has painted it
 * yet. Poll the live DOM for the expected bubble count before reading shape,
 * rather than trusting a single read right after navigation.
 */
function transcriptShapeWaitForBubbles(AwaitableWebpage $page, int $expected): int
{
    $count = 0;
    for ($i = 0; $i < 50; $i++) {
        $count = $page->script(<<<'JS'
            (() => document.querySelectorAll('[data-user-bubble]').length)();
        JS);
        if ($count === $expected) {
            return $count;
        }
        usleep(100_000);
    }

    return $count;
}

it('groups messages under a 3-minute gap and renders exactly one day separator across a day change', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    transcriptShapeInsertConversation($conversationId, $user, $team->getKey());

    $baseline = Carbon::parse('2026-08-19 10:00:00', 'UTC');

    // Chronological order: a message from yesterday, then three today, the
    // first pair one minute apart (must group), the third four minutes after
    // the second (must not group).
    transcriptShapeInsertMessage($conversationId, $user, 'Yesterday message', $baseline->copy()->subDay());
    transcriptShapeInsertMessage($conversationId, $user, 'First today message', $baseline->copy());
    transcriptShapeInsertMessage($conversationId, $user, 'Second today message', $baseline->copy()->addMinute());
    transcriptShapeInsertMessage($conversationId, $user, 'Third today message', $baseline->copy()->addMinutes(5));

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('Third today message');

    expect(transcriptShapeWaitForBubbles($page, 4))->toBe(4);

    $shape = $page->script(<<<'JS'
        (() => {
            const bubbles = Array.from(document.querySelectorAll('[data-user-bubble]'));
            return {
                daySeparatorCount: document.querySelectorAll('[data-day-separator]').length,
                groupedCount: document.querySelectorAll('[data-user-bubble][data-grouped]').length,
                bubbles: bubbles.map((el) => ({
                    text: el.textContent.trim(),
                    grouped: el.hasAttribute('data-grouped'),
                })),
            };
        })();
    JS);

    expect($shape['daySeparatorCount'])->toBe(1);
    expect($shape['groupedCount'])->toBe(1);

    $byText = collect($shape['bubbles'])->keyBy(fn (array $b): string => $b['text']);

    expect($byText->get('Yesterday message')['grouped'] ?? null)->toBeFalse();
    expect($byText->get('First today message')['grouped'] ?? null)->toBeFalse();
    expect($byText->get('Second today message')['grouped'] ?? null)->toBeTrue();
    expect($byText->get('Third today message')['grouped'] ?? null)->toBeFalse();
});

/**
 * Auto-load-earlier-on-scroll-up: a top sentinel drives loadEarlierMessages()
 * via IntersectionObserver, guarded (see loadEarlier() in transcript.js). IDs
 * are deterministic, lexicographically sortable strings (not uuid7) so
 * ORDER BY m.id in ListConversationMessages does not rest on uuid7's
 * monotonicity under a tight insert loop, the same pattern already used by
 * tests/Feature/Chat/MessagePaginationTest.php for this exact ordering
 * concern.
 */
function transcriptShapeInsertSequencedMessages(string $conversationId, User $user, int $count, Carbon $baseline): void
{
    $rows = [];

    foreach (range(1, $count) as $i) {
        $rows[] = [
            'id' => sprintf('seq-%04d', $i),
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => (string) $user->getKey(),
            'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
            'role' => 'user',
            'content' => sprintf('Seeded message %04d', $i),
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => $baseline->copy()->addMinutes($i),
            'updated_at' => $baseline->copy()->addMinutes($i),
        ];
    }

    DB::table('agent_conversation_messages')->insert($rows);
}

function transcriptShapeTopBubbleText(AwaitableWebpage $page): ?string
{
    return $page->script(<<<'JS'
        (() => {
            const bubbles = document.querySelectorAll('[data-user-bubble]');
            return bubbles.length > 0 ? bubbles[0].textContent.trim() : null;
        })();
    JS);
}

function transcriptShapeScrollToTop(AwaitableWebpage $page): void
{
    $page->script(<<<'JS'
        (() => {
            const container = document.querySelector('[data-chat-context="conversation"] [role="log"]');
            if (container) container.scrollTop = 0;
        })();
    JS);
}

/**
 * Whether the bubble containing $text is within the transcript container's
 * currently visible viewport (not merely present somewhere in the DOM).
 */
function transcriptShapeBubbleInViewport(AwaitableWebpage $page, string $text): bool
{
    $textJson = json_encode($text);

    return (bool) $page->script(<<<JS
        (() => {
            const container = document.querySelector('[data-chat-context="conversation"] [role="log"]');
            if (!container) return false;
            const bubbles = Array.from(container.querySelectorAll('[data-user-bubble]'));
            const target = bubbles.find((el) => el.textContent.includes({$textJson}));
            if (!target) return false;
            const containerRect = container.getBoundingClientRect();
            const targetRect = target.getBoundingClientRect();
            return targetRect.bottom > containerRect.top && targetRect.top < containerRect.bottom;
        })();
    JS);
}

it('auto-loads earlier messages on scroll-to-top, without a click, preserving scroll position', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    transcriptShapeInsertConversation($conversationId, $user, $team->getKey());

    $baseline = Carbon::parse('2026-08-19 08:00:00', 'UTC');
    transcriptShapeInsertSequencedMessages($conversationId, $user, 120, $baseline);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('Seeded message 0120');

    // Only the most recent 50 render initially, and the top-sentinel observer
    // must NOT have fired yet: on a normal-height viewport, 50 short bubbles
    // fill it well past the fold, so scrollToBottom(true) on mount leaves the
    // sentinel far off-screen above the scroll position.
    expect(transcriptShapeWaitForBubbles($page, 50))->toBe(50);

    $topBeforeScroll = transcriptShapeTopBubbleText($page);
    expect($topBeforeScroll)->toBe('Seeded message 0071');

    // No click anywhere: this is the entire point under test. Scrolling the
    // real container to 0 is a genuine layout change a native
    // IntersectionObserver reacts to, not a synthetic event a click handler
    // would need.
    transcriptShapeScrollToTop($page);

    expect(transcriptShapeWaitForBubbles($page, 100))->toBe(100);

    // The message that was at the top before the prepend must still be
    // visible after the scroll-height-delta restoration runs, not merely
    // present somewhere further down in the DOM.
    expect(transcriptShapeBubbleInViewport($page, 'Seeded message 0071'))->toBeTrue();

    $topAfterLoad = transcriptShapeTopBubbleText($page);
    expect($topAfterLoad)->toBe('Seeded message 0021');

    // No runaway loop: settling for half a second must not silently keep
    // pulling further pages just because the observer stayed attached.
    usleep(500_000);
    expect(transcriptShapeWaitForBubbles($page, 100))->toBe(100);

    // Recovery: the guard must re-arm after a successful load, not just once.
    // A second genuine scroll-to-top pulls the final 20 messages, and
    // hasMoreMessages must flip false (button disappears) once true history
    // end is reached.
    transcriptShapeScrollToTop($page);
    expect(transcriptShapeWaitForBubbles($page, 120))->toBe(120);

    $buttonStillPresent = $page->script(<<<'JS'
        (() => Array.from(document.querySelectorAll('button')).some((b) => b.textContent.includes('Load earlier messages')))();
    JS);
    expect($buttonStillPresent)->toBeFalse();
});

it('guards against a duplicate load when triggered again while one is already in flight', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    transcriptShapeInsertConversation($conversationId, $user, $team->getKey());

    $baseline = Carbon::parse('2026-08-19 08:00:00', 'UTC');
    transcriptShapeInsertSequencedMessages($conversationId, $user, 120, $baseline);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('Seeded message 0120');

    expect(transcriptShapeWaitForBubbles($page, 50))->toBe(50);

    $resolveInterface = ChatBrowser::resolveInterface();

    // Both calls happen synchronously within one script() execution, i.e.
    // strictly before either promise can settle (that requires a real
    // network round trip): the second call therefore exercises the guard
    // deterministically, not as a race that timing happens to win.
    $flagRightAfterDoubleCall = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.loadEarlier();
            data.loadEarlier();
            return data.loadingEarlier;
        })();
    JS);
    expect($flagRightAfterDoubleCall)->toBeTrue();

    expect(transcriptShapeWaitForBubbles($page, 100))->toBe(100);

    $flagAfterSettle = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            return data.loadingEarlier;
        })();
    JS);
    expect($flagAfterSettle)->toBeFalse();

    // The discriminator: an unguarded second call would either duplicate the
    // fetched page (well over 100) or, if Livewire happened to sequence the
    // two calls server-side, silently consume a second real page (also over
    // 100). Only the guarded path lands exactly on 100.
    usleep(500_000);
    $finalCount = $page->script(<<<'JS'
        (() => document.querySelectorAll('[data-user-bubble]').length)();
    JS);
    expect($finalCount)->toBe(100);
});

it('clears the in-flight guard on a failed request, so history loading is not permanently disabled', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    transcriptShapeInsertConversation($conversationId, $user, $team->getKey());

    $baseline = Carbon::parse('2026-08-19 08:00:00', 'UTC');
    transcriptShapeInsertSequencedMessages($conversationId, $user, 120, $baseline);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('Seeded message 0120');

    expect(transcriptShapeWaitForBubbles($page, 50))->toBe(50);

    $resolveInterface = ChatBrowser::resolveInterface();

    // Livewire's $wire is a Proxy whose get/set traps resolve method names
    // straight through to the live component on every access (confirmed by
    // reading vendor/livewire/livewire/dist/livewire.js: generateWireObject's
    // set() only ever writes into the reactive `state` bag, never `target`,
    // and get() falls through to getFallback() for anything not in that bag).
    // Assigning over `data.$wire.loadEarlierMessages` is a silent no-op,
    // it would still call the real method. Breaking window.fetch (which
    // Livewire's own request pipeline calls directly, see sendRequest() in
    // the same file) is what actually makes the underlying request reject,
    // so loadEarlier()'s .catch() is exercised against a real promise
    // rejection, not a stub that never engages.
    $afterFailure = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.__originalFetch = window.fetch;
            window.fetch = () => Promise.reject(new Error('simulated network failure'));
            data.loadEarlier();
            return data.loadingEarlier;
        })();
    JS);
    expect($afterFailure)->toBeTrue();

    $settledAfterFailure = null;
    for ($i = 0; $i < 30; $i++) {
        $settledAfterFailure = $page->script(<<<JS
            (() => {
                {$resolveInterface}
                return data.loadingEarlier;
            })();
        JS);
        if ($settledAfterFailure === false) {
            break;
        }
        usleep(100_000);
    }
    expect($settledAfterFailure)->toBeFalse();

    // Still exactly 50: the failed attempt must not have prepended anything.
    expect(transcriptShapeWaitForBubbles($page, 50))->toBe(50);

    // Re-arm proof: restore the real transport, then a genuine scroll-to-top
    // still successfully loads the next real page. If the guard had wedged
    // true, this would time out at 50 forever.
    $page->script('(() => { window.fetch = window.__originalFetch; return true; })();');
    transcriptShapeScrollToTop($page);
    expect(transcriptShapeWaitForBubbles($page, 100))->toBe(100);
});

it('auto-loads on mount when a short first page leaves the sentinel visible without any scroll', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    transcriptShapeInsertConversation($conversationId, $user, $team->getKey());

    $baseline = Carbon::parse('2026-08-19 08:00:00', 'UTC');
    // One more than PAGE_SIZE (50): the initial fetch returns the newest 50
    // and reports hasMoreMessages = true, leaving exactly one message behind.
    transcriptShapeInsertSequencedMessages($conversationId, $user, 51, $baseline);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->resize(1400, 9000)
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('Seeded message 0051');

    // No scroll action anywhere in this test: on a viewport this tall, 50
    // short single-line bubbles do not fill the container, so the sentinel is
    // visible the instant the observer attaches and must fire without the
    // user ever moving the scrollbar.
    expect(transcriptShapeWaitForBubbles($page, 51))->toBe(51);
    expect(transcriptShapeTopBubbleText($page))->toBe('Seeded message 0001');
});
