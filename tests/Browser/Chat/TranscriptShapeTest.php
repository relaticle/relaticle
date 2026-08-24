<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Support\MarkdownRenderer;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);

/**
 * Message grouping + day separators: consecutive same-role messages under a
 * 3-minute gap (and no pending actions on the earlier one) render tightly
 * grouped (`data-grouped` on the later bubble); a calendar-day change between
 * two adjacent messages renders exactly one `data-day-separator` marker.
 */
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

it('groups messages under a 3-minute gap and renders exactly one day separator across a day change', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'transcript shape', $conversationId);

    $baseline = Carbon::parse('2026-08-19 10:00:00', 'UTC');

    // Chronological order: a message from yesterday, then three today, the
    // first pair one minute apart (must group), the third four minutes after
    // the second (must not group).
    transcriptShapeInsertMessage($conversationId, $user, 'Yesterday message', $baseline->copy()->subDay());
    transcriptShapeInsertMessage($conversationId, $user, 'First today message', $baseline->copy());
    transcriptShapeInsertMessage($conversationId, $user, 'Second today message', $baseline->copy()->addMinute());
    transcriptShapeInsertMessage($conversationId, $user, 'Third today message', $baseline->copy()->addMinutes(5));

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Third today message');

    $page->assertCount('[data-user-bubble]', 4);

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
    ChatBrowser::seedConversation($user, $team->getKey(), 'transcript shape', $conversationId);

    $baseline = Carbon::parse('2026-08-19 08:00:00', 'UTC');
    transcriptShapeInsertSequencedMessages($conversationId, $user, 120, $baseline);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Seeded message 0120');

    // Only the most recent 50 render initially, and the top-sentinel observer
    // must NOT have fired yet: on a normal-height viewport, 50 short bubbles
    // fill it well past the fold, so scrollToBottom(true) on mount leaves the
    // sentinel far off-screen above the scroll position.
    $page->assertCount('[data-user-bubble]', 50);

    $topBeforeScroll = transcriptShapeTopBubbleText($page);
    expect($topBeforeScroll)->toBe('Seeded message 0071');

    // No click anywhere: this is the entire point under test. Scrolling the
    // real container to 0 is a genuine layout change a native
    // IntersectionObserver reacts to, not a synthetic event a click handler
    // would need.
    transcriptShapeScrollToTop($page);

    $page->assertCount('[data-user-bubble]', 100);

    // The message that was at the top before the prepend must still be
    // visible after the scroll-height-delta restoration runs, not merely
    // present somewhere further down in the DOM.
    expect(transcriptShapeBubbleInViewport($page, 'Seeded message 0071'))->toBeTrue();

    $topAfterLoad = transcriptShapeTopBubbleText($page);
    expect($topAfterLoad)->toBe('Seeded message 0021');

    // No runaway loop: settling for half a second must not silently keep
    // pulling further pages just because the observer stayed attached.
    usleep(500_000);
    $page->assertCount('[data-user-bubble]', 100);

    // Recovery: the guard must re-arm after a successful load, not just once.
    // A second genuine scroll-to-top pulls the final 20 messages, and
    // hasMoreMessages must flip false (button disappears) once true history
    // end is reached.
    transcriptShapeScrollToTop($page);

    // Deliberately a usleep poll, NOT assertCount: the auto-retrying
    // assertion hammers the CDP bridge in a tight loop, and (verified
    // empirically, 4/4 runs) the in-process server then never finishes
    // serving THIS second back-to-back load-earlier request, the count
    // stays 100 for the whole 30s retry budget. The quiet usleep windows
    // leave the shared event loop free to serve the XHR; the same poll is
    // why this test passed before the assertCount migration.
    $count = 0;
    for ($i = 0; $i < 50; $i++) {
        $count = $page->script(<<<'JS'
            (() => document.querySelectorAll('[data-user-bubble]').length)();
        JS);
        if ($count === 120) {
            break;
        }
        usleep(100_000);
    }
    expect($count)->toBe(120);

    $buttonStillPresent = $page->script(<<<'JS'
        (() => Array.from(document.querySelectorAll('button')).some((b) => b.textContent.includes('Load earlier messages')))();
    JS);
    expect($buttonStillPresent)->toBeFalse();
});

it('guards against a duplicate load when triggered again while one is already in flight', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'transcript shape', $conversationId);

    $baseline = Carbon::parse('2026-08-19 08:00:00', 'UTC');
    transcriptShapeInsertSequencedMessages($conversationId, $user, 120, $baseline);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Seeded message 0120');

    $page->assertCount('[data-user-bubble]', 50);

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

    $page->assertCount('[data-user-bubble]', 100);

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
    ChatBrowser::seedConversation($user, $team->getKey(), 'transcript shape', $conversationId);

    $baseline = Carbon::parse('2026-08-19 08:00:00', 'UTC');
    transcriptShapeInsertSequencedMessages($conversationId, $user, 120, $baseline);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Seeded message 0120');

    $page->assertCount('[data-user-bubble]', 50);

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
    $page->assertCount('[data-user-bubble]', 50);

    // Re-arm proof: restore the real transport, then a genuine scroll-to-top
    // still successfully loads the next real page. If the guard had wedged
    // true, this would time out at 50 forever.
    $page->script('(() => { window.fetch = window.__originalFetch; return true; })();');
    transcriptShapeScrollToTop($page);
    $page->assertCount('[data-user-bubble]', 100);
});

it('auto-loads on mount when a short first page leaves the sentinel visible without any scroll', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'transcript shape', $conversationId);

    $baseline = Carbon::parse('2026-08-19 08:00:00', 'UTC');
    // One more than PAGE_SIZE (50): the initial fetch returns the newest 50
    // and reports hasMoreMessages = true, leaving exactly one message behind.
    transcriptShapeInsertSequencedMessages($conversationId, $user, 51, $baseline);

    $page = ChatBrowser::logIn($user, $team->slug)
        ->resize(1400, 9000)
        ->navigate("/app/{$team->slug}/chats/{$conversationId}")
        ->assertSourceHas('Seeded message 0051');

    // No scroll action anywhere in this test: on a viewport this tall, 50
    // short single-line bubbles do not fill the container, so the sentinel is
    // visible the instant the observer attaches and must fire without the
    // user ever moving the scrollbar.
    $page->assertCount('[data-user-bubble]', 51);
    expect(transcriptShapeTopBubbleText($page))->toBe('Seeded message 0001');
});

/** Polls the transcript for the expected number of painted record chips. */
/**
 * A `/r/{type}/{id}` citation inside an assistant reply paints as a chip on
 * BOTH render pipelines, and the two agree.
 *
 * `rendered && !prerendered` is the client pipeline: a reply that just finished
 * streaming, put through `window.renderMarkdown` (marked + DOMPurify + the
 * post-sanitize chip sweep). `rendered && prerendered` is the server pipeline:
 * the same reply rehydrated on reload, already HTML from `MarkdownRenderer`.
 * Both bubbles are mounted in one transcript here and their chip markup is
 * compared against the server's own output character for character, so the two
 * implementations cannot drift apart unnoticed.
 */
it('paints a cited record as the same chip on the streamed and the reloaded pipeline', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $markdown = 'Closed [Acme Corporation](/r/company/01ABCDEF) this morning.';
    $serverHtml = (new MarkdownRenderer)->render($markdown);

    expect(preg_match('#<a class="chat-chip".+?</a>#s', $serverHtml, $chipMatch))->toBe(1);
    $expectedChip = $chipMatch[0];

    $markdownJson = json_encode($markdown, JSON_THROW_ON_ERROR);
    $serverHtmlJson = json_encode($serverHtml, JSON_THROW_ON_ERROR);

    $page = ChatBrowser::logIn($user, $team->slug)
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            const base = { role: 'assistant', rendered: true, editing: false, editText: '', follow_ups: [] };

            data.messages.push({ ...base, content: {$markdownJson}, prerendered: false });
            data.messages.push({ ...base, content: {$serverHtmlJson}, prerendered: true });

            return true;
        })();
    JS);

    $page->assertCount('.chat-chip', 2);

    $chips = json_decode((string) $page->script(<<<'JS'
        (() => {
            const chips = Array.from(document.querySelectorAll('.chat-chip'));

            return JSON.stringify({
                markup: chips.map((c) => c.outerHTML),
                labels: chips.map((c) => c.textContent.trim()),
                hrefs: chips.map((c) => c.getAttribute('href')),
                types: chips.map((c) => c.getAttribute('data-record-type')),
                navigating: chips.some((c) => c.hasAttribute('wire:navigate')),
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($chips['markup'])->toBe([$expectedChip, $expectedChip])
        ->and($chips['labels'])->toBe(['Acme Corporation', 'Acme Corporation'])
        ->and($chips['hrefs'])->toBe(['/r/company/01ABCDEF', '/r/company/01ABCDEF'])
        ->and($chips['types'])->toBe(['company', 'company'])
        // `/r/` is a server redirect, so the chip must be a plain navigation.
        ->and($chips['navigating'])->toBeFalse();
});

/**
 * Copying a reply has to hand over links someone can paste anywhere, so the
 * root-relative `/r/` citations are absolutized against the current origin.
 * Both message shapes are covered because `msg.content` is markdown for a reply
 * rendered in this session and server HTML for one rehydrated on reload.
 */
it('copies a cited record as an absolute url from both message shapes', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $markdown = 'Closed [Acme Corporation](/r/company/01ABCDEF) this morning.';
    $markdownJson = json_encode($markdown, JSON_THROW_ON_ERROR);
    $serverHtmlJson = json_encode((new MarkdownRenderer)->render($markdown), JSON_THROW_ON_ERROR);

    $page = ChatBrowser::logIn($user, $team->slug)
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $result = json_decode((string) $page->script(<<<JS
        (async () => {
            {$resolveInterface}

            const copied = [];
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: { writeText: (text) => { copied.push(text); return Promise.resolve(); } },
            });

            const base = { role: 'assistant', rendered: true, editing: false, editText: '', follow_ups: [] };
            const streamed = { ...base, content: {$markdownJson}, prerendered: false };
            const reloaded = { ...base, content: {$serverHtmlJson}, prerendered: true };

            data.messages.push(streamed, reloaded);

            await data.copyMessage(streamed);
            await data.copyMessage(reloaded);

            return JSON.stringify({ origin: window.location.origin, copied });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    $origin = $result['origin'];

    expect($result['copied'])->toHaveCount(2)
        ->and($result['copied'][0])->toContain("]({$origin}/r/company/01ABCDEF)")
        ->and($result['copied'][1])->toContain("href=\"{$origin}/r/company/01ABCDEF\"")
        ->and($result['copied'][0])->not->toContain('](/r/')
        ->and($result['copied'][1])->not->toContain('href="/r/');
});

/** The chip anchor inside a rendered reply, or null when nothing was chipped. */
function transcriptShapeExtractChip(string $html): ?string
{
    return preg_match('#<a class="chat-chip".+?</a>#s', $html, $matches) === 1
        ? $matches[0]
        : null;
}

/**
 * The drift guard for the CLIENT half of the contract. The PHP suite can only
 * reach `RecordChipRenderer::ICONS`; nothing but this case can see
 * `RECORD_CHIP_ICONS` in chat.js, so without it a sixth citable type could be
 * added server-side and the suite would stay green while streamed replies
 * showed a plain link and reloads showed a chip.
 *
 * Every type in `RecordReferenceResolver::CHIP_TYPES` must round-trip to
 * markup identical to the server's, and the negatives must chip on NEITHER
 * side: `custom_field` is a real reference type with no per-record route, and
 * `__proto__`/`constructor` both satisfy the client's `[a-z_]+` type grammar
 * and resolve through `Object.prototype` on a plain object literal.
 *
 * The label carries a soft line break so the same case also pins the two
 * spellings of a break (`<br />` plus a newline server-side, `<br>` from
 * marked) to one flattened label on both sides.
 */
it('emits chip markup identical to the server for every citable type, and chips nothing else', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $renderer = new MarkdownRenderer;
    $neverChipped = ['custom_field', '__proto__', 'constructor'];
    $cases = [];
    $expected = [];

    foreach ([...RecordReferenceResolver::CHIP_TYPES, ...$neverChipped] as $type) {
        $markdown = "See [Acme\nCorporation](/r/{$type}/01ABCDEF) now.";

        // A list of pairs, never an object keyed by type: `{"__proto__": ...}`
        // as a JS object literal sets the prototype instead of an own property,
        // which would quietly neuter the case that matters most here.
        $cases[] = [$type, $markdown];
        $expected[] = [$type, transcriptShapeExtractChip($renderer->render($markdown))];
    }

    $casesJson = json_encode($cases, JSON_THROW_ON_ERROR);

    $page = ChatBrowser::logIn($user, $team->slug)
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $actual = json_decode((string) $page->script(<<<JS
        (() => {
            const cases = {$casesJson};

            return JSON.stringify(cases.map(([type, markdown]) => {
                const chip = window.renderMarkdown(markdown).match(/<a class="chat-chip"[\\s\\S]+?<\\/a>/);

                return [type, chip ? chip[0] : null];
            }));
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($actual)->toBe($expected);

    // Guards the guard: agreeing on "no chip everywhere" would satisfy the
    // comparison above, so pin which side of the line each type falls on.
    $serverChipByType = array_column($expected, 1, 0);

    foreach (RecordReferenceResolver::CHIP_TYPES as $type) {
        expect($serverChipByType[$type])->toContain('class="chat-chip"')
            ->toContain('<span class="chat-chip-label">Acme Corporation</span>');
    }

    foreach ($neverChipped as $type) {
        expect($serverChipByType[$type])->toBeNull();
    }
});
