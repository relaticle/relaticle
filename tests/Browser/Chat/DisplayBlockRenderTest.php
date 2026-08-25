<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);
mutates(ListConversationMessages::class);

/**
 * A read tool's `display_block` envelope, painted as a real table/card in the
 * transcript instead of a markdown table the model wrote itself.
 *
 * The fixtures below are shaped like production payloads on purpose:
 *  - a promoted (filtered/sorted) column sits IN FRONT of the record's own
 *    name, so linking "the first cell" would link the wrong one;
 *  - one row is missing a column key entirely, because formatStored() emits
 *    nothing for a field a record holds no value for;
 *  - a link field carries a URL far longer than the free-text cap, which a
 *    blanket truncation would turn into a broken href.
 */
/**
 * @param  list<array<string, mixed>>  $blocks
 */
function displayBlockInsertAssistantMessage(string $conversationId, User $user, string $content, array $blocks, int $secondsAgo): void
{
    // A null entry represents a tool call without a display block. It still
    // occupies a marker position.
    $toolResults = array_map(static fn (?array $block, int $index): array => [
        'id' => 'toolu_block_'.$index,
        'name' => $block === null ? 'GetCrmSummaryTool' : 'ListCompaniesTool',
        'arguments' => [],
        'result' => json_encode($block === null ? ['data' => []] : ['data' => [], 'display_block' => $block]),
    ], $blocks, array_keys($blocks));

    foreach (['user' => $content.' request', 'assistant' => $content] as $role => $text) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => (string) $user->getKey(),
            'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
            'role' => $role,
            'content' => $text,
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => $role === 'assistant' ? (string) json_encode($toolResults) : '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->subSeconds($secondsAgo),
            'updated_at' => now()->subSeconds($secondsAgo),
        ]);
    }
}

/**
 * @return array<string, mixed>
 */
function displayBlockTableFixture(): array
{
    return [
        'block' => 'records_table',
        'title' => 'Companies',
        'type' => 'company',
        'core' => 'name',
        'columns' => [
            ['key' => 'deal_source', 'label' => 'Deal Source'],
            ['key' => 'name', 'label' => 'Name'],
            ['key' => 'segment', 'label' => 'Segment'],
        ],
        'rows' => [
            [
                'id' => '01ACME',
                'url' => '/r/company/01ACME',
                'cells' => ['deal_source' => 'Referral', 'name' => 'Acme Corporation'],
            ],
        ],
        'total' => 12,
    ];
}

/**
 * A records_table block shaped like a real BaseReadListTool payload with
 * $rowCount rows on the page and $total across the whole result set. Passing
 * $openUrl mirrors BaseReadListTool::openUrlFor: present only when the tool's
 * own pagination has a further page to send the user to.
 *
 * @return array<string, mixed>
 */
function displayBlockLongTableFixture(int $rowCount, int $total, ?string $openUrl = null): array
{
    $rows = array_map(static fn (int $n): array => [
        'id' => sprintf('01ROW%02d', $n),
        'url' => sprintf('/r/company/01ROW%02d', $n),
        'cells' => ['name' => "Company {$n}"],
    ], range(1, $rowCount));

    return [
        'block' => 'records_table',
        'title' => 'Companies',
        'type' => 'company',
        'core' => 'name',
        'columns' => [
            ['key' => 'name', 'label' => 'Name'],
        ],
        'rows' => $rows,
        'total' => $total,
        'from' => 1,
        ...($openUrl !== null ? ['open_url' => $openUrl] : []),
    ];
}

/**
 * @return array<string, mixed>
 */
function displayBlockCardFixture(string $longUrl): array
{
    return [
        'block' => 'record_card',
        'title' => 'Acme Corporation',
        'type' => 'company',
        'url' => '/r/company/01ACME',
        'fields' => [
            ['label' => 'Segment', 'value' => 'Enterprise, Manufacturing', 'type' => 'badges', 'values' => ['Enterprise', 'Manufacturing']],
            ['label' => 'Domains', 'value' => $longUrl, 'type' => 'link', 'values' => [$longUrl]],
        ],
    ];
}

it('paints persisted read results as a real table and card, and drops an unknown block type', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    $longUrl = 'https://example.com/?ref='.str_repeat('a', 600);

    // The unknown type rides in the SAME message as the table: a registry miss
    // must leave nothing behind, not an empty frame above the real block.
    displayBlockInsertAssistantMessage($conversationId, $user, 'Here are your companies.', [
        displayBlockTableFixture(),
        ['block' => 'retired_block_type', 'title' => 'Nope'],
    ], 120);

    displayBlockInsertAssistantMessage($conversationId, $user, 'Here is Acme Corporation.', [
        displayBlockCardFixture($longUrl),
    ], 60);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Here is Acme Corporation.');

    $page->assertCount('[data-block]', 2);

    $shape = json_decode((string) $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('[data-block="records_table"]');
            const card = document.querySelector('[data-block="record_card"]');
            // querySelectorAll never descends into a <template>, so this reads
            // only the cells Alpine actually painted, in document order.
            const cells = Array.from(table.querySelectorAll('tbody tr td'));
            const tableChips = Array.from(table.querySelectorAll('.chat-chip'));
            const cardChip = card.querySelector('.chat-chip');
            const cardLink = card.querySelector('a[target="_blank"]');

            return JSON.stringify({
                blockTypes: Array.from(document.querySelectorAll('[data-block]')).map((el) => el.dataset.block),
                headers: Array.from(table.querySelectorAll('thead th')).map((el) => el.textContent.trim()),
                cells: cells.map((el) => el.textContent.trim()),
                linkedCellIndex: cells.findIndex((el) => el.querySelector('.chat-chip')),
                tableChipCount: tableChips.length,
                tableChipHref: tableChips[0]?.getAttribute('href') ?? null,
                tableChipType: tableChips[0]?.getAttribute('data-record-type') ?? null,
                tableChipNavigating: tableChips.some((c) => c.hasAttribute('wire:navigate')),
                footer: table.textContent.includes('Showing 1 of 12'),
                cardChipLabel: cardChip?.textContent.trim() ?? null,
                cardChipHref: cardChip?.getAttribute('href') ?? null,
                cardFieldRows: card.querySelectorAll('[data-record-field-row]').length,
                cardBadges: Array.from(card.querySelectorAll('span')).map((el) => el.textContent.trim()),
                cardLinkHref: cardLink?.getAttribute('href') ?? null,
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    // The registry decides what paints: 'retired_block_type' leaves no node.
    expect($shape['blockTypes'])->toBe(['records_table', 'record_card']);

    expect($shape['headers'])->toBe(['Deal Source', 'Name', 'Segment']);

    // The promoted column leads the table, so the record link must hang off
    // the CORE column instead of the first one, and the column the row holds
    // no value for renders empty rather than throwing.
    expect($shape['linkedCellIndex'])->toBe(1)
        ->and($shape['cells'])->toBe(['Referral', 'Acme Corporation', ''])
        ->and($shape['tableChipCount'])->toBe(1)
        ->and($shape['tableChipHref'])->toBe('/r/company/01ACME')
        ->and($shape['tableChipType'])->toBe('company')
        // `/r/` is a server redirect, so the chip is a plain navigation.
        ->and($shape['tableChipNavigating'])->toBeFalse()
        ->and($shape['footer'])->toBeTrue();

    expect($shape['cardChipLabel'])->toBe('Acme Corporation')
        ->and($shape['cardChipHref'])->toBe('/r/company/01ACME')
        ->and($shape['cardFieldRows'])->toBe(2)
        // Real chips per option, not one comma-joined string.
        ->and($shape['cardBadges'])->toContain('Enterprise', 'Manufacturing')
        ->and($shape['cardBadges'])->not->toContain('Enterprise, Manufacturing')
        // A capped URL is a broken href.
        ->and($shape['cardLinkHref'])->toBe($longUrl);
});

it('places a block at its {{block:N}} marker inside the reply and appends unplaced blocks below', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    $longUrl = 'https://example.com/?ref=inline';

    displayBlockInsertAssistantMessage(
        $conversationId,
        $user,
        "Lead-in before the table.\n\n{{block:1}}\n\nCommentary after the table.",
        [displayBlockTableFixture(), displayBlockCardFixture($longUrl)],
        60,
    );

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Commentary after the table.');

    $shape = json_decode((string) $page->script(<<<'JS'
        (() => {
            const bubble = document.querySelector('[data-assistant-bubble]');
            const parts = Array.from(bubble.querySelectorAll('.prose, [data-block]'))
                .map((el) => el.dataset.block ?? ('text:' + el.textContent.trim().slice(0, 12)));

            return JSON.stringify({
                parts,
                leaksMarker: bubble.textContent.includes('{{block:'),
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    // Reading order: lead-in, the marked table exactly where the marker sat,
    // the commentary, then the unplaced card appended below the reply.
    expect($shape['parts'])->toBe(['text:Lead-in befo', 'records_table', 'text:Commentary a', 'record_card'])
        ->and($shape['leaksMarker'])->toBeFalse();
});

it('honors markers separated by single newlines, the shape the model actually writes', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    // Single newlines reflect the model's observed output.
    // Parsers fold these markers into one paragraph unless normalization isolates them.
    displayBlockInsertAssistantMessage(
        $conversationId,
        $user,
        "Here is everything:\n{{block:1}}\n{{block:2}}\nEnjoy!",
        [displayBlockTableFixture(), displayBlockCardFixture('https://example.com/?ref=tight')],
        60,
    );

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Enjoy!');

    $shape = json_decode((string) $page->script(<<<'JS'
        (() => {
            const bubble = document.querySelector('[data-assistant-bubble]');
            const parts = Array.from(bubble.querySelectorAll('.prose, [data-block]'))
                .map((el) => el.dataset.block ?? ('text:' + el.textContent.trim().slice(0, 12)));

            return JSON.stringify({
                parts,
                leaksMarker: bubble.textContent.includes('{{block:'),
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($shape['parts'])->toBe(['text:Here is ever', 'records_table', 'record_card', 'text:Enjoy!'])
        ->and($shape['leaksMarker'])->toBeFalse();
});

it('resolves markers by tool-call order when a blockless tool was called first', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    // The first tool emits no block. Therefore, marker 2 maps to the first
    // display block instead of array index 2.
    displayBlockInsertAssistantMessage(
        $conversationId,
        $user,
        "Overview first.\n\n{{block:2}}\n\n{{block:3}}\n\nDone.",
        [null, displayBlockTableFixture(), displayBlockCardFixture('https://example.com/?ref=order')],
        60,
    );

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Overview first.');

    $shape = json_decode((string) $page->script(<<<'JS'
        (() => {
            const bubble = document.querySelector('[data-assistant-bubble]');
            const parts = Array.from(bubble.querySelectorAll('.prose, [data-block]'))
                .map((el) => el.dataset.block ?? ('text:' + el.textContent.trim().slice(0, 12)));

            return JSON.stringify({
                parts,
                leaksMarker: bubble.textContent.includes('{{block:'),
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($shape['parts'])->toBe(['text:Overview fir', 'records_table', 'record_card', 'text:Done.'])
        ->and($shape['leaksMarker'])->toBeFalse();
});

it('renders streamed text as markdown and hides incomplete trailing tokens', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    displayBlockInsertAssistantMessage($conversationId, $user, 'Earlier turn.', [], 60);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Earlier turn.');

    $resolveInterface = ChatBrowser::resolveInterface();

    // The stream ends with completed Markdown, a block marker, and an incomplete link.
    // Raw syntax must never paint.
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.isStreaming = true;
            const stub = data.targetBubbleFor('inv-stream-md');
            stub.content = 'Deals worth **\$78,000** total.\\n\\n{{block:1}}\\n\\nSee the [import guide](https://example.com/guide), [Missing record](null), and the [People imp';
            return true;
        })();
    JS);

    $painted = json_decode((string) $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[x-html="streamingHtml(msg)"]');
            return JSON.stringify({
                bold: !!el.querySelector('strong'),
                link: el.querySelector('a')?.getAttribute('href') ?? null,
                linkCount: el.querySelectorAll('a').length,
                text: el.textContent,
            });
        })();
    JS), true, 1024, JSON_THROW_ON_ERROR);

    expect($painted['bold'])->toBeTrue()
        ->and($painted['link'])->toBe('https://example.com/guide')
        ->and($painted['linkCount'])->toBe(1)
        ->and($painted['text'])->toContain('Missing record')
        ->and($painted['text'])->not->toContain('**')
        ->and($painted['text'])->not->toContain('{{block')
        ->and($painted['text'])->not->toContain('[People imp')
        ->and($painted['text'])->not->toContain('](');
});

it('attaches display blocks to the streamed bubble at stream-end reconcile', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    displayBlockInsertAssistantMessage($conversationId, $user, 'Here are your companies.', [
        displayBlockTableFixture(),
    ], 60);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Here are your companies.');

    $page->assertCount('[data-block]', 1);

    $resolveInterface = ChatBrowser::resolveInterface();

    // Stand in for a turn that just streamed: a fresh bubble carrying only the
    // streamed text, with no blocks, exactly as mintAssistantStub() leaves it.
    // Reverb never carries a block (10 KB frame cap), so reconcile is the only
    // path that can put one on screen before a reload.
    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            data.mintAssistantStub({ content: 'Here are your companies.', invocationId: 'inv-reconcile' });

            return true;
        })();
    JS);

    // The real finalize path: handleStreamEnd() reconciles from the DB and
    // marks the bubble rendered, which is what actually puts the block on
    // screen. Blocks paint only on rendered replies (they interleave with the
    // message's html segments), so calling reconcile alone would assert an
    // intermediate state no user ever sees.
    $page->script(<<<JS
        (async () => {
            {$resolveInterface}

            await data.handleStreamEnd({ invocation_id: 'inv-reconcile' });

            return true;
        })();
    JS);

    $page->assertCount('[data-block]', 2);
});

it('collapses a table past ten rows and reveals the rest when the toggle is clicked', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    // 25 rows on the page (the model's whole page, per BaseReadListTool/D1),
    // 42 across the full result set: the footer's count must keep tracking
    // total minus VISIBLE rows through the toggle, never settle at "42 of 42".
    displayBlockInsertAssistantMessage($conversationId, $user, 'Here are your companies.', [
        displayBlockLongTableFixture(25, 42),
    ], 60);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Here are your companies.');

    $collapsed = json_decode((string) $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('[data-block="records_table"]');
            const toggle = table.querySelector('[data-block-toggle]');
            const scrollRegion = table.querySelector('[role="region"]');
            const openLink = table.querySelector('[data-block-open-link]');

            return JSON.stringify({
                rowCount: table.querySelectorAll('tbody tr').length,
                toggleLabel: toggle?.textContent.trim() ?? null,
                toggleExpanded: toggle?.getAttribute('aria-expanded') ?? null,
                toggleIsButton: toggle?.tagName ?? null,
                footerShows10Of42: table.textContent.includes('Showing 10 of 42'),
                // Finding 2: the header strip must carry ONLY the title and the
                // count now, never the open-list link (that moved to the bottom
                // bar), so the title never has to fight it for room.
                openLinkInHeader: !!table.querySelector('.border-b [data-block-open-link]'),
                // Finding 3: the scroll region is its own aria-live="off" island
                // (rows expand/collapse silently), the toggle's aria-controls
                // points at that exact same element, and the id is derived from
                // the deterministic blockKey(msg, block), not a minted UUID.
                scrollRegionAriaLive: scrollRegion?.getAttribute('aria-live') ?? null,
                scrollRegionId: scrollRegion?.id ?? null,
                toggleControlsScrollRegion: toggle?.getAttribute('aria-controls') === scrollRegion?.id,
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($collapsed['rowCount'])->toBe(10)
        ->and($collapsed['toggleLabel'])->toBe('Show all 25 rows')
        ->and($collapsed['toggleExpanded'])->toBe('false')
        ->and($collapsed['toggleIsButton'])->toBe('BUTTON')
        ->and($collapsed['footerShows10Of42'])->toBeTrue()
        ->and($collapsed['openLinkInHeader'])->toBeFalse()
        ->and($collapsed['scrollRegionAriaLive'])->toBe('off')
        ->and($collapsed['scrollRegionId'])->not->toBeNull()
        ->and($collapsed['toggleControlsScrollRegion'])->toBeTrue();

    $page->click('[data-block="records_table"] [data-block-toggle]');

    $expanded = json_decode((string) $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('[data-block="records_table"]');
            const toggle = table.querySelector('[data-block-toggle]');

            return JSON.stringify({
                rowCount: table.querySelectorAll('tbody tr').length,
                toggleLabel: toggle?.textContent.trim() ?? null,
                toggleExpanded: toggle?.getAttribute('aria-expanded') ?? null,
                footerShows25Of42: table.textContent.includes('Showing 25 of 42'),
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($expanded['rowCount'])->toBe(25)
        ->and($expanded['toggleLabel'])->toBe('Show fewer')
        ->and($expanded['toggleExpanded'])->toBe('true')
        ->and($expanded['footerShows25Of42'])->toBeTrue();
});

it('renders no toggle for a table with exactly the collapse threshold of rows', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    displayBlockInsertAssistantMessage($conversationId, $user, 'Here are your companies.', [
        displayBlockLongTableFixture(10, 10),
    ], 60);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Here are your companies.');

    $shape = json_decode((string) $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('[data-block="records_table"]');

            return JSON.stringify({
                rowCount: table.querySelectorAll('tbody tr').length,
                hasToggle: !!table.querySelector('[data-block-toggle]'),
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($shape['rowCount'])->toBe(10)
        ->and($shape['hasToggle'])->toBeFalse();
});

it('renders the open_url link to the entity list page when the tool has more pages', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    $openUrl = 'https://relaticle.test/app/'.$team->slug.'/companies';

    // Under the collapse threshold on purpose: `open_url` is the tool's OWN
    // "more pages exist" signal (D5), independent of the client-side row
    // toggle, so it must render even when there is nothing to collapse.
    displayBlockInsertAssistantMessage($conversationId, $user, 'Here are your companies.', [
        displayBlockLongTableFixture(6, 42, $openUrl),
    ], 60);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Here are your companies.');

    $shape = json_decode((string) $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('[data-block="records_table"]');
            const link = table.querySelector('[data-block-open-link]');

            return JSON.stringify({
                href: link?.getAttribute('href') ?? null,
                label: link?.textContent.trim() ?? null,
                navigating: link?.hasAttribute('wire:navigate') ?? false,
                hasToggle: !!table.querySelector('[data-block-toggle]'),
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($shape['href'])->toBe($openUrl)
        ->and($shape['label'])->toBe('Open all 42 in Companies')
        ->and($shape['navigating'])->toBeFalse()
        ->and($shape['hasToggle'])->toBeFalse();
});

it('expands two records_table blocks in one message independently', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    // Both blocks come from the SAME message, so blockKey(msg, block) can only
    // tell them apart via block.tool_call_order (DisplayBlocks::collect() stamps
    // it per tool call: 1 for the first ListCompaniesTool call, 2 for the
    // second). If the key ever collapsed back to something message-only,
    // expanding one would expand both.
    displayBlockInsertAssistantMessage($conversationId, $user, 'Here are two tables.', [
        displayBlockLongTableFixture(25, 25),
        displayBlockLongTableFixture(15, 15),
    ], 60);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Here are two tables.');

    $page->assertCount('[data-block="records_table"]', 2);

    // Expand only the FIRST table.
    $page->script(<<<'JS'
        (() => {
            document.querySelectorAll('[data-block="records_table"] [data-block-toggle]')[0].click();
            return true;
        })();
    JS);

    $shape = json_decode((string) $page->script(<<<'JS'
        (() => {
            const tables = Array.from(document.querySelectorAll('[data-block="records_table"]'));

            return JSON.stringify(tables.map((table) => {
                const scrollRegion = table.querySelector('[role="region"]');
                return {
                    rowCount: table.querySelectorAll('tbody tr').length,
                    toggleLabel: table.querySelector('[data-block-toggle]')?.textContent.trim() ?? null,
                    scrollRegionId: scrollRegion?.id ?? null,
                };
            }));
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($shape[0]['rowCount'])->toBe(25)
        ->and($shape[0]['toggleLabel'])->toBe('Show fewer')
        ->and($shape[1]['rowCount'])->toBe(10)
        ->and($shape[1]['toggleLabel'])->toBe('Show all 15 rows')
        // Two blocks, two distinct scroll-region ids: the aria-controls wiring
        // (finding 3) can only target the RIGHT table when these differ.
        ->and($shape[0]['scrollRegionId'])->not->toBe($shape[1]['scrollRegionId']);
});

it('keeps a table expanded across a second stream-end reconcile that replaces the block object', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = ChatBrowser::seedConversation($user, $team->getKey(), 'display blocks');

    displayBlockInsertAssistantMessage($conversationId, $user, 'Here are your companies.', [
        displayBlockLongTableFixture(25, 25),
    ], 60);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('Here are your companies.');

    $page->click('[data-block="records_table"] [data-block-toggle]');

    $afterExpand = json_decode((string) $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('[data-block="records_table"]');
            return JSON.stringify({ rowCount: table.querySelectorAll('tbody tr').length });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($afterExpand['rowCount'])->toBe(25);

    $resolveInterface = ChatBrowser::resolveInterface();

    // handleStreamEnd() is a real entry point (also fired by the lost-stream
    // watchdog) that calls reconcileLatestAssistant() internally; calling that
    // function bare here would also flip `rendered` false (the DB content is
    // raw text, the already-hydrated bubble's is markdown-rendered HTML, so
    // reconcileLatestAssistant's own content-diff branch fires) without ever
    // flipping it back, since in production ONLY the caller does that. Going
    // through handleStreamEnd exercises the exact real sequence: no active
    // invocation_id matches this history-loaded message, so it falls back to
    // lastAssistantBubble(), which resolves to our already-rendered,
    // already-expanded bubble. reconcileLatestAssistant then replaces
    // assistantMsg.display_blocks WHOLESALE with a brand-new array of
    // brand-new block objects fetched fresh from $wire.latestAssistantMessage()
    // (stream.js:443-444) - the exact mutation the lost-stream watchdog and a
    // duplicate stream_end both perform on one turn.
    $page->script(<<<JS
        (async () => {
            {$resolveInterface}

            await data.handleStreamEnd({ invocation_id: null });

            return true;
        })();
    JS);

    $afterReconcile = json_decode((string) $page->script(<<<'JS'
        (() => {
            const table = document.querySelector('[data-block="records_table"]');
            const toggle = table.querySelector('[data-block-toggle]');

            return JSON.stringify({
                rowCount: table.querySelectorAll('tbody tr').length,
                toggleLabel: toggle?.textContent.trim() ?? null,
                toggleExpanded: toggle?.getAttribute('aria-expanded') ?? null,
            });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    // Before finding 1's fix this reconcile silently re-collapsed the table:
    // the fresh block object from the server carried no __uiKey, so
    // blockIsExpanded() looked up nothing and fell back to false.
    expect($afterReconcile['rowCount'])->toBe(25)
        ->and($afterReconcile['toggleLabel'])->toBe('Show fewer')
        ->and($afterReconcile['toggleExpanded'])->toBe('true');
});
