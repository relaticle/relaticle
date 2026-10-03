<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;

mutates(ChatInterface::class);

/**
 * @return array{top: ?int, scrollTop: int, jumpVisible: bool, anchorKey: ?string, replyHeight: int, belowFold: int}
 */
function transcriptAnchorPosition(AwaitableWebpage $page): array
{
    $resolveInterface = ChatBrowser::resolveInterface();

    $probe = <<<JS
        (async () => {
            {$resolveInterface}

            await new Promise((r) => setTimeout(r, 900));

            const scroller = document.querySelector('[data-chat-context="conversation"] [role="log"]');
            const row = document.querySelector('[data-client-key="' + data.anchorKey + '"]');
            const replies = document.querySelectorAll('[data-assistant-bubble]');
            const reply = replies[replies.length - 1];

            return JSON.stringify({
                top: row ? Math.round(row.getBoundingClientRect().top - scroller.getBoundingClientRect().top) : null,
                scrollTop: Math.round(scroller.scrollTop),
                jumpVisible: ! data.pinnedToBottom,
                anchorKey: data.anchorKey,
                replyHeight: reply ? Math.round(reply.getBoundingClientRect().height) : 0,
                belowFold: Math.round(scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight),
            });
        })();
    JS;

    foreach (range(1, 3) as $attempt) {
        $position = json_decode((string) $page->script($probe), true);

        if (is_array($position)) {
            return $position;
        }
    }

    throw new RuntimeException('The anchor probe never returned a position.');
}

function transcriptAnchorOpenTwentyMessageConversation(string $title): AwaitableWebpage
{
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), $title, $conversationId);
    ChatBrowser::seedSequencedMessages($conversationId, $user, 20, Date::parse('2026-08-19 08:00:00', 'UTC'));

    return ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('Seeded message 0020');
}

function transcriptAnchorRun(AwaitableWebpage $page, string $body): mixed
{
    $resolveInterface = ChatBrowser::resolveInterface();

    return $page->script(<<<JS
        (() => {
            {$resolveInterface}
            {$body}
        })();
    JS);
}

it('anchors a sent message near the top and holds it there while the reply and its late content land', function (): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('send anchor');

    transcriptAnchorRun($page, <<<'JS'
        window.fetch = () => new Promise(() => {});
        data.localEditor().setText('Show me my companies in a table.');
        data.sendMessage();

        return true;
    JS);

    $sent = transcriptAnchorPosition($page);
    $sentMessageKey = transcriptAnchorRun($page, <<<'JS'
        return data.messages.findLast((m) => m.role === 'user').clientKey;
    JS);

    transcriptAnchorRun($page, <<<'JS'
        data.handleStreamStart({ invocation_id: 'inv-anchor' });
        data.handleTextDelta({ invocation_id: 'inv-anchor', delta: 'Here are your four companies.' });

        return true;
    JS);

    $streaming = transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        const reply = data.lastAssistantBubble();
        reply.display_blocks = [{
            block: 'records_table',
            title: 'Companies',
            type: 'company',
            core: 'name',
            columns: [{ key: 'name', label: 'Name' }],
            rows: ['Airbnb', 'Apple', 'Figma', 'Notion'].map((name) => ({ id: name, url: '/r/company/' + name, cells: { name } })),
            total: 4,
        }];
        reply.rendered = true;
        data.isStreaming = false;
        data.nextSteps = [{ label: 'Import companies from a file', prompt: 'Import companies from a file' }];

        return true;
    JS);

    $settled = transcriptAnchorPosition($page);

    expect($sent['anchorKey'])->toBe($sentMessageKey)
        ->and($sent['top'])->toBe(128)
        ->and([$streaming['top'], $streaming['scrollTop']])->toBe([$sent['top'], $sent['scrollTop']])
        ->and([$settled['top'], $settled['scrollTop']])->toBe([$sent['top'], $sent['scrollTop']])
        ->and($settled['replyHeight'])->toBeGreaterThan($streaming['replyHeight'] + 150)
        ->and($settled['jumpVisible'])->toBeFalse();
});

it('lands the next steps of a short anchored turn directly under the reply', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'short anchored turn');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    transcriptAnchorRun($page, <<<'JS'
        window.fetch = () => new Promise(() => {});
        data.localEditor().setText('How many companies do I have?');
        data.sendMessage();

        return true;
    JS);

    transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        data.handleStreamStart({ invocation_id: 'inv-short' });
        data.handleTextDelta({ invocation_id: 'inv-short', delta: 'You have four companies.' });
        data.lastAssistantBubble().rendered = true;
        data.isStreaming = false;
        data.nextSteps = [{ label: 'Show them in a table', prompt: 'Show them in a table' }];

        return true;
    JS);

    transcriptAnchorPosition($page);

    $gap = transcriptAnchorRun($page, <<<'JS'
        const scope = document.querySelector('[data-chat-context="conversation"]');
        const replies = scope.querySelectorAll('[data-assistant-bubble]');
        const reply = replies[replies.length - 1].getBoundingClientRect();
        const step = scope.querySelector('[data-next-step]').getBoundingClientRect();

        return Math.round(step.top - reply.bottom);
    JS);

    expect($gap)->toBeLessThan(80);
});

it('keeps the last lines of the previous reply in view above an anchored message', function (): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('previous reply peek');

    transcriptAnchorRun($page, <<<'JS'
        const paragraph = (i) => 'Line of reasoning ' + i + ' about the pipeline, long enough to wrap across the transcript column.';
        data.messages.push(data.ensureClientKey({
            role: 'assistant',
            content: Array.from({ length: 8 }, (_, i) => paragraph(i + 1)).join(String.fromCharCode(10, 10)),
            rendered: true,
            prerendered: false,
            pending_actions: [],
            display_blocks: [],
        }));
        window.fetch = () => new Promise(() => {});
        data.localEditor().setText('And the next question.');
        data.sendMessage();

        return true;
    JS);

    $sent = transcriptAnchorPosition($page);

    $previousTextBottom = transcriptAnchorRun($page, <<<'JS'
        const scroller = document.querySelector('[data-chat-context="conversation"] [role="log"]');
        const lastParagraph = [...scroller.querySelectorAll('[data-assistant-bubble] p')]
            .find((p) => p.textContent.startsWith('Line of reasoning 8 '));

        return Math.round(lastParagraph.getBoundingClientRect().bottom - scroller.getBoundingClientRect().top);
    JS);

    expect($sent['top'])->toBe(128)
        ->and($previousTextBottom)->toBeGreaterThanOrEqual(48);
});

it('anchors a message sent from a next-step suggestion', function (): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('next step anchor');

    transcriptAnchorRun($page, <<<'JS'
        window.fetch = () => new Promise(() => {});
        data.nextSteps = [{ label: 'Show my open deals', prompt: 'Show my open deals' }];

        return true;
    JS);

    transcriptAnchorRun($page, <<<'JS'
        document.querySelector('[data-chat-context="conversation"] [data-next-step]').click();

        return true;
    JS);

    $sent = transcriptAnchorPosition($page);
    $sentMessageKey = transcriptAnchorRun($page, <<<'JS'
        return data.messages.findLast((m) => m.role === 'user').clientKey;
    JS);

    expect($sent['anchorKey'])->toBe($sentMessageKey)
        ->and($sent['top'])->toBe(128);
});

it('keeps the anchored message in place when content above it grows during and after the scroll', function (): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('growth above anchor');

    transcriptAnchorRun($page, <<<'JS'
        window.fetch = () => new Promise(() => {});
        data.localEditor().setText('Show me my companies in a table.');
        data.sendMessage();

        const previousRow = () => {
            const rows = document.querySelectorAll('[data-chat-context="conversation"] [data-client-key]');

            return rows[rows.length - 3];
        };
        setTimeout(() => { previousRow().style.paddingBottom = '40px'; }, 150);

        return true;
    JS);

    $duringScroll = transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        const rows = document.querySelectorAll('[data-chat-context="conversation"] [data-client-key]');
        rows[rows.length - 3].style.paddingBottom = '90px';

        return true;
    JS);

    $afterScroll = transcriptAnchorPosition($page);

    expect($duringScroll['top'])->toBe(128)
        ->and($afterScroll['top'])->toBe(128);
});

it('lets a reply taller than the viewport run below the fold instead of dragging the transcript after it', function (): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('long reply');

    transcriptAnchorRun($page, <<<'JS'
        window.fetch = () => new Promise(() => {});
        data.localEditor().setText('Summarise every deal in detail.');
        data.sendMessage();

        return true;
    JS);

    $before = transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        data.handleStreamStart({ invocation_id: 'inv-long' });
        data.handleTextDelta({
            invocation_id: 'inv-long',
            delta: Array.from({ length: 60 }, (_, i) => 'Deal ' + (i + 1) + ' moved forward this week.').join(String.fromCharCode(10, 10)),
        });

        return true;
    JS);

    $after = transcriptAnchorPosition($page);

    expect([$after['top'], $after['scrollTop']])->toBe([$before['top'], $before['scrollTop']])
        ->and($after['belowFold'])->toBeGreaterThan(200)
        ->and($after['jumpVisible'])->toBeTrue();
});

it('offers the jump button when late content of an anchored turn lands just below the fold', function (): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('late content below fold');

    transcriptAnchorRun($page, <<<'JS'
        window.fetch = () => new Promise(() => {});
        data.localEditor().setText('How many companies do I have?');
        data.sendMessage();

        return true;
    JS);

    transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        data.handleStreamStart({ invocation_id: 'inv-late' });
        data.handleTextDelta({ invocation_id: 'inv-late', delta: 'You have four companies.' });
        data.lastAssistantBubble().rendered = true;
        data.isStreaming = false;

        return true;
    JS);

    $settled = transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        const replies = document.querySelectorAll('[data-chat-context="conversation"] [data-assistant-bubble]');
        replies[replies.length - 1].style.paddingBottom = (data.$refs.anchorReserve.offsetHeight + 30) + 'px';

        return true;
    JS);

    $overflowed = transcriptAnchorPosition($page);

    expect([$overflowed['top'], $overflowed['scrollTop']])->toBe([$settled['top'], $settled['scrollTop']])
        ->and($overflowed['belowFold'])->toBeGreaterThan(20)->toBeLessThan(80)
        ->and($overflowed['jumpVisible'])->toBeTrue();
});

it('anchors a resumed turn on its own reply while the reader rests on an anchored reply that outgrew the viewport', function (): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('resume after long reply');

    transcriptAnchorRun($page, <<<'JS'
        window.fetch = () => new Promise(() => {});
        data.localEditor().setText('Summarise every deal in detail.');
        data.sendMessage();

        return true;
    JS);

    transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        data.handleStreamStart({ invocation_id: 'inv-long-proposal' });
        data.handleTextDelta({
            invocation_id: 'inv-long-proposal',
            delta: Array.from({ length: 60 }, (_, i) => 'Deal ' + (i + 1) + ' moved forward this week.').join(String.fromCharCode(10, 10)),
        });
        data.lastAssistantBubble().rendered = true;
        data.isStreaming = false;

        return true;
    JS);

    transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        data.handleStreamStart({ invocation_id: 'inv-resumed' });

        return true;
    JS);

    $resumed = transcriptAnchorPosition($page);
    $resumedReplyKey = transcriptAnchorRun($page, <<<'JS'
        return data.lastAssistantBubble().clientKey;
    JS);

    expect($resumed['anchorKey'])->toBe($resumedReplyKey)
        ->and($resumed['top'])->toBe(128);
});

it('follows the rest of a long reply once the reader jumps to the latest message', function (): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('jump while anchored');

    transcriptAnchorRun($page, <<<'JS'
        window.fetch = () => new Promise(() => {});
        data.localEditor().setText('Summarise every deal in detail.');
        data.sendMessage();

        return true;
    JS);

    transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        data.handleStreamStart({ invocation_id: 'inv-follow' });
        data.handleTextDelta({
            invocation_id: 'inv-follow',
            delta: Array.from({ length: 60 }, (_, i) => 'Deal ' + (i + 1) + ' moved forward this week.').join(String.fromCharCode(10, 10)),
        });

        return true;
    JS);

    transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        Array.from(document.querySelectorAll('[data-chat-context="conversation"] [role="log"] button'))
            .find((el) => el.getAttribute('title') === 'Scroll to latest messages')
            .click();

        return true;
    JS);

    transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        data.handleTextDelta({
            invocation_id: 'inv-follow',
            delta: String.fromCharCode(10, 10) + Array.from({ length: 20 }, (_, i) => 'Note ' + (i + 1) + ' on the pipeline.').join(String.fromCharCode(10, 10)),
        });

        return true;
    JS);

    $followed = transcriptAnchorPosition($page);

    expect($followed['belowFold'])->toBeLessThan(2)
        ->and($followed['jumpVisible'])->toBeFalse();
});

it('keeps the transcript still while a resent turn replaces the anchored one', function (string $prepare, string $resend): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('resent anchor');

    transcriptAnchorRun($page, <<<'JS'
        window.fetch = () => new Promise(() => {});
        data.localEditor().setText('Show me my companies in a table.');
        data.sendMessage();

        return true;
    JS);

    transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        data.handleStreamStart({ invocation_id: 'inv-regenerate' });
        data.handleTextDelta({ invocation_id: 'inv-regenerate', delta: 'You have four companies.' });
        data.lastAssistantBubble().rendered = true;
        data.isStreaming = false;

        return true;
    JS);

    transcriptAnchorRun($page, "{$prepare} return true;");

    $settled = transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<JS
        window.fetch = (url) => String(url).includes('/supersede')
            ? new Promise((resolve) => setTimeout(() => resolve(new Response('{}', { status: 200 })), 300))
            : new Promise(() => {});

        const scroller = document.querySelector('[data-chat-context="conversation"] [role="log"]');
        window.paintedScrollTops = [Math.round(scroller.scrollTop)];
        const painted = new ResizeObserver(() => window.paintedScrollTops.push(Math.round(scroller.scrollTop)));
        [scroller, scroller.querySelector('[x-ref="messageColumn"]')].forEach((el) => painted.observe(el));

        {$resend}

        return true;
    JS);

    $resent = transcriptAnchorPosition($page);
    $paintedScrollTops = json_decode((string) transcriptAnchorRun($page, <<<'JS'
        return JSON.stringify(window.paintedScrollTops);
    JS), true, 512, JSON_THROW_ON_ERROR);
    $resentMessageKey = transcriptAnchorRun($page, <<<'JS'
        return data.messages.findLast((m) => m.role === 'user').clientKey;
    JS);

    expect(array_values(array_unique($paintedScrollTops)))->toBe([$settled['scrollTop']])
        ->and($resent['anchorKey'])->toBe($resentMessageKey)
        ->and($resent['top'])->toBe(128);
})->with([
    'regenerate' => ['', 'data.regenerateMessage(data.messages.length - 1);'],
    'regenerate with the clamp scroll event first' => [
        <<<'JS'
            const log = document.querySelector('[data-chat-context="conversation"] [role="log"]');
            new MutationObserver((records) => {
                if (records.some((record) => record.removedNodes.length > 0)) {
                    log.dispatchEvent(new Event('scroll'));
                }
            }).observe(log, { childList: true, subtree: true });
        JS,
        'data.regenerateMessage(data.messages.length - 1);',
    ],
    'edit' => [
        'data.startEdit(data.messages.findLast((m) => m.role === "user"));',
        <<<'JS'
            const sent = data.messages.findLast((m) => m.role === 'user');
            sent.editText = 'Show me my companies in a list.';
            data.saveEdit(sent, data.messages.indexOf(sent));
        JS,
    ],
]);

it('anchors a turn this tab did not send on its own reply', function (): void {
    $page = transcriptAnchorOpenTwentyMessageConversation('resumed turn');

    transcriptAnchorRun($page, <<<'JS'
        data.messages.push(data.ensureClientKey({ role: 'assistant', content: 'Review the proposal below.', rendered: true, prerendered: false, pending_actions: [], display_blocks: [] }));
        data.scrollToBottom(true);

        return true;
    JS);

    transcriptAnchorPosition($page);

    transcriptAnchorRun($page, <<<'JS'
        data.handleStreamStart({ invocation_id: 'inv-resume' });

        return true;
    JS);

    $resumed = transcriptAnchorPosition($page);
    $resumedReplyKey = transcriptAnchorRun($page, <<<'JS'
        return data.lastAssistantBubble().clientKey;
    JS);

    expect($resumed['anchorKey'])->toBe($resumedReplyKey)
        ->and($resumed['top'])->toBe(128);
});
