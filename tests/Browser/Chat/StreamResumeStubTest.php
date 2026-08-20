<?php

declare(strict_types=1);

use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;

mutates(ChatInterface::class);

/**
 * Issue #503: mintAssistantStub() returned the raw pre-push stub instead of
 * re-reading the pushed element out of the reactive `messages` array. Alpine
 * (built on @vue/reactivity) only tracks and triggers effects through the
 * array-read proxy: a mutation on the raw pre-push object bypasses the proxy's
 * set trap entirely, so trigger() never fires for it.
 *
 * targetBubbleFor()'s resume fallback (`return this.mintAssistantStub(...)`)
 * hands that raw reference straight to callers like handleTextDelta, which
 * mutate `.content` on it. Confirmed live while investigating this issue that
 * the natural SINGLE-event call sequence self-heals: Alpine defers a brand
 * new array item's very first render to a microtask, and by the time that
 * first render actually runs, the synchronous mint+mutate has already
 * completed, so the "cold start" effect reads the post-mutation value fresh
 * regardless of whether the write went through the proxy. The failure only
 * becomes PERMANENT once an effect has already mounted (reading through the
 * proxy once) and a LATER mutation reuses the same stale raw reference
 * instead of re-reading the array — exactly what the second assertion below
 * reproduces, and exactly the shape mintAssistantStub()'s return value
 * invites any caller to fall into (the same class already fixed once in
 * send.js's optimistic user bubble).
 */
it('mints the assistant stub as the same reference the reactive messages array holds', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    // Before the fix: mintAssistantStub() returns the plain pre-push object;
    // messages[i] is a distinct Alpine/Vue reactive proxy wrapping the same
    // underlying target, so `===` is false. After the fix: the function
    // re-reads the pushed element, so both sides are the identical proxy.
    $identityMatches = (bool) $page->script(<<<JS
        (() => {
            {$resolveInterface}
            const raw = data.mintAssistantStub({ invocationId: 'identity-check' });
            const viaArray = data.messages[data.messages.length - 1];
            return raw === viaArray;
        })();
    JS);

    expect($identityMatches)->toBeTrue();
});

/** Polls the last assistant bubble's rendered `msg.content` text until it matches or times out. */
function streamResumeStubPollBubbleText(AwaitableWebpage $page, string $expected): ?string
{
    $expectedJson = json_encode($expected, JSON_THROW_ON_ERROR);
    $last = null;

    for ($i = 0; $i < 30; $i++) {
        $last = $page->script(<<<'JS'
            (() => {
                const bubbles = document.querySelectorAll('[data-assistant-bubble]');
                const last = bubbles[bubbles.length - 1];
                const el = last ? last.querySelector('[x-text="msg.content"]') : null;
                return el ? el.textContent : null;
            })();
        JS);

        if ($last === $expectedJson || $last === $expected) {
            return $last;
        }

        usleep(100_000);
    }

    return $last;
}

it('paints a later mutation on the reference targetBubbleFor()\'s resume fallback returns', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    // Forces targetBubbleFor()'s resume fallback: no assistant bubble exists
    // yet for this invocation, matching a resume where handleStreamStart was
    // dropped and the first event this client sees is a text_delta.
    // Captures the SAME reference handleTextDelta's `assistantMsg` would be
    // (targetBubbleFor's return value), so the mutation below is exactly
    // `assistantMsg.content += delta` mid-stream, not a synthetic shortcut.
    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            window.Echo = null;
            data.isStreaming = true;
            window.__resumeStub = data.targetBubbleFor('resume-inv-1');
            window.__resumeStub.content = 'first chunk';
            return true;
        })();
    JS);

    // A brand new array item's first-ever paint reads fresh state regardless
    // of the bug (Alpine defers the render to a microtask, by which point
    // the synchronous mutation above has already landed) — expected to pass
    // both pre- and post-fix, establishing the mounted effect this test
    // actually probes with the next mutation.
    $firstPaint = streamResumeStubPollBubbleText($page, 'first chunk');
    expect($firstPaint)->toBe('first chunk');

    // The mounted x-text effect from the first paint is now subscribed to
    // this object's `content` property. Mutating the SAME reference again
    // must still repaint. Pre-fix, `window.__resumeStub` is the plain
    // pre-push object, not the reactive proxy Alpine's effect tracked: the
    // write lands (JS state updates) but never triggers, so the DOM stays
    // stuck on "first chunk" forever.
    $page->script(<<<'JS'
        (() => { window.__resumeStub.content += ' second chunk'; return true; })();
    JS);

    $secondPaint = streamResumeStubPollBubbleText($page, 'first chunk second chunk');
    expect($secondPaint)->toBe('first chunk second chunk');
});
