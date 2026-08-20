<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Pest\Browser\Playwright\Playwright;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;

mutates(ChatInterface::class);

/**
 * Laravel 13's ThrottleRequests middleware hashes named-limiter cache keys by
 * default (`self::$shouldHashKeys` is true), so the literal `'chat-send:'.$team
 * ->getKey()` string a PHP-side RateLimiter::hit() call would write to is NOT
 * the key the middleware actually checks (verified empirically against the real
 * app: only real fetch() calls made THROUGH the browser accumulate against it).
 * So the 429 here is forced with real warmup requests, not facade manipulation.
 * $this->travel() IS honored (Carbon's test-now is a shared class static), so
 * it fast-forwards past the one-minute decay window instead of sleeping for it.
 *
 * Every individual $page->script() call below stays short (a single fetch, or
 * a single synchronous state read) rather than looping internally: $page is an
 * AwaitableWebpage, and each of its calls is retried by Pest under its own
 * short internal timeout if it does not settle quickly. A script that runs a
 * 10-request warmup loop AND a multi-second poll loop in one call risks a
 * retry re-invoking (not resuming) that same slow script, firing a second,
 * overlapping burst of real requests underneath it (found live: exactly this
 * shape 429'd on a request nothing in this file intended to send). Splitting
 * warmup into 10 one-request calls, and polling into repeated one-read calls
 * with the wait living in PHP (usleep), keeps each individual call fast and
 * side-effect-free-on-retry.
 *
 * None of these tests navigate to the dedicated `/chats` page. Visiting it
 * directly (no conversation id, no `?prompt=` param, no `chat:bootstrap`
 * sessionStorage handoff from the dashboard) trips the page's own "No Dead
 * Ends" guard (`chat-conversation.blade.php`), which client-side redirects
 * straight back to the dashboard. That redirect is deliberate product
 * behavior, not a bug: chats are meant to start from the dashboard composer.
 * So every test below drives the dashboard's own `chatInterface` Alpine
 * component directly (the same one that backs the side panel drawer) rather
 * than fighting that redirect. It starts visually collapsed, but its Alpine
 * state and DOM are fully live, so script-driven reads and writes work
 * exactly as they would on an expanded page.
 */
function chatWarmUpRateLimiter(AwaitableWebpage $page): void
{
    // 10 real sequential HTTP round trips through the in-process server, each
    // sharing this dev box's CPU with every other Horizon/Reverb worker across
    // sibling checkouts (chronic load average well above core count here). The
    // default 30s Playwright action timeout is occasionally too tight for a
    // single round trip under that contention; widen it just for this loop
    // rather than for the whole file, so a genuine hang elsewhere still fails
    // fast.
    Playwright::usingTimeout(60_000, function () use ($page): void {
        for ($i = 0; $i < 10; $i++) {
            $page->script(<<<'JS'
                (async () => {
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                    await fetch('/chat/conversations', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                        body: JSON.stringify({ document: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'warmup' }] }] } }),
                    });
                    return true;
                })();
            JS);
        }
    });
}

/**
 * Polls the last user message's sendState via short, independent reads (no
 * side effects, so a Pest-level retry of any single read is harmless) until
 * it matches $targetState or attempts run out. Returns null on timeout.
 */
function chatPollUserSendState(AwaitableWebpage $page, string $resolveInterface, string $targetState): ?string
{
    for ($i = 0; $i < 60; $i++) {
        $state = $page->script(<<<JS
            (() => {
                {$resolveInterface}
                const msg = data.messages.find((m) => m.role === 'user');
                return msg?.sendState ?? null;
            })();
        JS);

        if ($state === $targetState) {
            return $state;
        }

        usleep(100_000);
    }

    return null;
}

it('carries the optimistic bubble through sending then sent on a real send', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    // Echo is stubbed out so the round trip does not wait on a real Reverb
    // channel subscription: this test is only about the fetch-driven
    // sendState transition, not the streaming pipeline. sendMessage() is
    // fired without being awaited inside the script (see file docblock).
    $sendingState = $page->script(<<<JS
        (() => {
            {$resolveInterface}

            window.Echo = null;
            data.localEditor().setText('hello state machine');
            data.sendMessage();

            return data.messages.find((m) => m.role === 'user')?.sendState ?? null;
        })();
    JS);

    expect($sendingState)->toBe('sending');

    $finalState = chatPollUserSendState($page, $resolveInterface, 'sent');

    expect($finalState)->toBe('sent');

    $domSentBubbles = $page->script(<<<'JS'
        (() => document.querySelectorAll('[data-user-bubble][data-send-state="sent"]').length)();
    JS);

    expect($domSentBubbles)->toBe(1);
});

/**
 * Sends a message on an already-loaded chat page after exhausting the rate
 * limiter, returning the failure snapshot once sendState settles to 'failed'.
 *
 * @return array{userCount: int, domUserBubbles: int, domFailedBubbles: int, clientKey: string|null}
 */
function chatTriggerRateLimitedFailure(AwaitableWebpage $page, string $resolveInterface): array
{
    $page->script(<<<'JS'
        (() => { window.Echo = null; return true; })();
    JS);

    chatWarmUpRateLimiter($page);

    // sendMessage() is awaited HERE, not fired-and-forgotten: it resolves fast
    // on a 429 (handleSendRateLimit runs synchronously once the single fetch
    // settles, no streaming wait involved), so sendState is already final by
    // the time this call returns and no poll is needed. A dangling unawaited
    // promise for THIS specific call was found to race the server's handling
    // of its own 429 response against whatever the next $page->script() call
    // did (observed live: an unrelated later script() call surfaced the 429's
    // HttpResponseException raw instead of it having been rendered into a
    // normal JSON response).
    //
    // Widened timeout for the same reason as chatWarmUpRateLimiter above: this
    // is the single call in the file that chains a real create+send round trip
    // through the throttle middleware, so it is the most exposed to this box's
    // background CPU load.
    $failedState = Playwright::usingTimeout(60_000, fn (): mixed => $page->script(<<<JS
        (async () => {
            {$resolveInterface}
            data.localEditor().setText('rate limited message');
            await data.sendMessage();
            return data.messages.find((m) => m.role === 'user')?.sendState ?? null;
        })();
    JS));

    expect($failedState)->toBe('failed');

    return $page->script(<<<JS
        (() => {
            {$resolveInterface}
            const failedMsg = data.messages.find((m) => m.role === 'user');
            return {
                userCount: data.messages.filter((m) => m.role === 'user').length,
                domUserBubbles: document.querySelectorAll('[data-user-bubble]').length,
                domFailedBubbles: document.querySelectorAll('[data-user-bubble][data-send-state="failed"]').length,
                clientKey: failedMsg?.clientKey ?? null,
            };
        })();
    JS);
}

it('marks an optimistic bubble failed on rate limit, and a real click resend does not duplicate it', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $afterFailure = chatTriggerRateLimitedFailure($page, $resolveInterface);

    expect($afterFailure['userCount'])->toBe(1);
    expect($afterFailure['domUserBubbles'])->toBe(1);
    expect($afterFailure['domFailedBubbles'])->toBe(1);
    expect($afterFailure['clientKey'])->not->toBeNull();

    // The failed bubble above lives in the dashboard's side-panel chatInterface
    // (see file docblock), which renders collapsed (x-show="open", open=false)
    // until the drawer is opened. A real Playwright click() requires the
    // target to be visible, so open the drawer directly through its own
    // Alpine state before clicking: this is a state change on the panel
    // wrapper, not on the chat message data under test.
    $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('[data-chat-side-panel]');
            Alpine.$data(panel).open = true;
            return true;
        })();
    JS);

    // A real click on the template's own <button x-on:click="resendMessage(msg)">
    // (via data-resend-button), not a script-invoked data.resendMessage() call:
    // this is the only check that exercises Alpine's `msg` scope resolution and
    // the `:disabled="isStreaming"` binding on the actual bound element. Unlike
    // the scripted sends above, nothing here awaits resendMessage()'s promise:
    // Alpine's x-on:click handler fires it and returns immediately, so the
    // click itself settles before the network round trip does. Poll for the
    // terminal state instead of reading it right after the click.
    $page->click('[data-resend-button]');

    $sendStateAfterClick = chatPollUserSendState($page, $resolveInterface, 'failed');

    expect($sendStateAfterClick)->toBe('failed');

    $stillFailed = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            const msg = data.messages.find((m) => m.role === 'user');
            return {
                sendState: msg?.sendState ?? null,
                clientKey: msg?.clientKey ?? null,
                userCount: data.messages.filter((m) => m.role === 'user').length,
                domUserBubbles: document.querySelectorAll('[data-user-bubble]').length,
            };
        })();
    JS);

    // Still rate-limited, so the click's resend attempt also 429s: same bubble,
    // same clientKey, still failed, never duplicated.
    expect($stillFailed['sendState'])->toBe('failed');
    expect($stillFailed['clientKey'])->toBe($afterFailure['clientKey']);
    expect($stillFailed['userCount'])->toBe(1);
    expect($stillFailed['domUserBubbles'])->toBe(1);
});

it('recovers a failed bubble to sent once the rate limit window clears, reusing the same clientKey', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $afterFailure = chatTriggerRateLimitedFailure($page, $resolveInterface);

    expect($afterFailure['clientKey'])->not->toBeNull();

    // Fast-forward past the one-minute decay window instead of sleeping:
    // Carbon's test-now is visible to the browser-serving execution context.
    $this->travel(61)->seconds();

    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            const failedMsg = data.messages.find((m) => m.role === 'user');
            data.resendMessage(failedMsg);
            return true;
        })();
    JS);

    $finalState = chatPollUserSendState($page, $resolveInterface, 'sent');

    expect($finalState)->toBe('sent');

    $afterResend = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            const resentMsg = data.messages.find((m) => m.role === 'user');
            return {
                userCount: data.messages.filter((m) => m.role === 'user').length,
                domUserBubbles: document.querySelectorAll('[data-user-bubble]').length,
                clientKey: resentMsg?.clientKey ?? null,
            };
        })();
    JS);

    expect($afterResend['userCount'])->toBe(1);
    expect($afterResend['domUserBubbles'])->toBe(1);
    // Same clientKey as the failed bubble: resend reused it in place rather
    // than minting a second bubble (the F2 fix).
    expect($afterResend['clientKey'])->toBe($afterFailure['clientKey']);
});

it('keeps the transcript non-empty when a regenerate resend hits the rate limit (issue #499)', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    // A real regenerate always happens inside an EXISTING conversation, so the
    // resend it triggers goes through /chat/{id} (deliverMessage's non-first-
    // message branch), never /chat/conversations. A real row (no messages
    // persisted yet, matching the client's still-optimistic turn) makes the
    // repro faithful: without conversationId set, regenerateMessage's resend
    // would take the wrong branch and 429 on conversation creation instead.
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();
    $conversationIdJson = json_encode($conversationId);

    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            window.Echo = null;
            data.conversationId = {$conversationIdJson};

            // Fabricate an existing turn (user + rendered assistant reply) as the
            // regenerate target, so regenerateMessage(1) has a real preceding
            // user message to resend.
            data.messages = [
                data.ensureClientKey({ role: 'user', content: 'first attempt', sendState: 'sent', editing: false, editText: '', copiedAt: 0, page_context: null }),
                data.ensureClientKey({ role: 'assistant', content: 'first reply', pending_actions: [], paywall: null, sessionExpired: false, rendered: true, prerendered: true, copiedAt: 0, follow_ups: [] }),
            ];
            return true;
        })();
    JS);

    chatWarmUpRateLimiter($page);

    $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.regenerateMessage(1);
            return true;
        })();
    JS);

    $failedState = chatPollUserSendState($page, $resolveInterface, 'failed');

    expect($failedState)->toBe('failed');

    $result = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            return {
                totalCount: data.messages.length,
                userCount: data.messages.filter((m) => m.role === 'user').length,
                failedCount: data.messages.filter((m) => m.sendState === 'failed').length,
                // Unchanged conversationId proves the resend took the non-first-
                // message branch (/chat/{id}) rather than creating a second
                // conversation via /chat/conversations.
                conversationIdUnchanged: data.conversationId === {$conversationIdJson},
            };
        })();
    JS);

    expect($result['totalCount'])->toBe(1);
    expect($result['userCount'])->toBe(1);
    expect($result['failedCount'])->toBe(1);
    expect($result['conversationIdUnchanged'])->toBeTrue();
});

it('leaves the transcript untouched when regenerate is triggered while an earlier send is still rate-limited (issue #499)', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    // Same reasoning as the fixture above: regenerate always happens inside
    // an EXISTING conversation.
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A real persisted row for the turn regenerateMessage() targets. Without
    // one, supersedeServerTurns()'s anchor lookup finds nothing to match and
    // a `superseded_at is still null` assertion below would pass whether or
    // not the guard exists, giving it no teeth.
    $targetMessageId = (string) Str::uuid7();
    DB::table('agent_conversation_messages')->insert([
        'id' => $targetMessageId,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'test',
        'role' => 'user',
        'content' => 'first attempt',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();
    $conversationIdJson = json_encode($conversationId);
    $targetMessageIdJson = json_encode($targetMessageId);

    // Fabricates the exact state issue #499 describes: a countdown from a
    // PRIOR failed send is already ticking (this.rateLimit is set) when the
    // user clicks Regenerate on an EARLIER assistant reply. No real 429 round
    // trip is needed: the defect is entirely in what regenerateMessage() does
    // to this.messages before sendMessage() is ever reached, so this
    // simulates the client-side rate-limited state directly instead of
    // warming up the real throttle middleware for it.
    $result = $page->script(<<<JS
        (async () => {
            {$resolveInterface}

            window.Echo = null;
            data.conversationId = {$conversationIdJson};

            data.messages = [
                data.ensureClientKey({ id: {$targetMessageIdJson}, role: 'user', content: 'first attempt', sendState: 'sent', editing: false, editText: '', copiedAt: 0, page_context: null }),
                data.ensureClientKey({ role: 'assistant', content: 'first reply', pending_actions: [], paywall: null, sessionExpired: false, rendered: true, prerendered: true, copiedAt: 0, follow_ups: [] }),
                data.ensureClientKey({ role: 'user', content: 'second attempt', sendState: 'failed', editing: false, editText: '', copiedAt: 0, page_context: null }),
            ];

            const failedMsg = data.messages[data.messages.length - 1];
            data.rateLimit = { secondsLeft: 30, timerId: null, userMsg: failedMsg };

            await data.regenerateMessage(1);

            return {
                totalCount: data.messages.length,
                roles: data.messages.map((m) => m.role),
                contents: data.messages.map((m) => m.content),
                rateLimitStillActive: data.rateLimit !== null,
                rateLimitSecondsLeft: data.rateLimit?.secondsLeft ?? null,
            };
        })();
    JS);

    // Before the fix: regenerateMessage() spliced from userIndex(0) onward,
    // removing all 3 messages (including the unrelated failed/rate-limited
    // one), then sendMessage() silently bailed on `if (this.rateLimit)
    // return`, leaving nothing behind. totalCount would be 0.
    expect($result['totalCount'])->toBe(3);
    expect($result['roles'])->toBe(['user', 'assistant', 'user']);
    expect($result['contents'])->toBe(['first attempt', 'first reply', 'second attempt']);
    expect($result['rateLimitStillActive'])->toBeTrue();
    expect($result['rateLimitSecondsLeft'])->toBe(30);

    // The server-truth assertion: the local array looking untouched is not
    // enough on its own, since reload reads from the DB. Before the fix,
    // regenerateMessage() called supersedeServerTurns() (stamping this row's
    // superseded_at) BEFORE ever reaching the rate-limit no-op in
    // sendMessage(), so the local splice-restoring fix above still left a
    // server-side supersede in place unless the guard runs first.
    $supersededAt = DB::table('agent_conversation_messages')
        ->where('id', $targetMessageId)
        ->value('superseded_at');

    expect($supersededAt)->toBeNull();
});

it('leaves the transcript untouched when retryTurn is triggered while an earlier send is still rate-limited (issue #499 sibling)', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();
    $conversationIdJson = json_encode($conversationId);

    // retryTurn() is the sibling of regenerateMessage() flagged in the #499
    // review: the same unconditional splice into a sendMessage() call that
    // silently no-ops while this.rateLimit is set. Its splice window is
    // narrower (just the retried turn), but the failed/rate-limited message
    // at the tail is never touched by that splice either way, so the same
    // guard is required to stop the retried turn itself from being lost.
    $result = $page->script(<<<JS
        (async () => {
            {$resolveInterface}

            window.Echo = null;
            data.conversationId = {$conversationIdJson};

            data.messages = [
                data.ensureClientKey({ role: 'user', content: 'first attempt', sendState: 'sent', editing: false, editText: '', copiedAt: 0, page_context: null }),
                data.ensureClientKey({ role: 'assistant', content: '', streamError: 'The assistant took too long to respond.', retryable: true, pending_actions: [], paywall: null, sessionExpired: false, rendered: true, prerendered: true, copiedAt: 0, follow_ups: [] }),
                data.ensureClientKey({ role: 'user', content: 'second attempt', sendState: 'failed', editing: false, editText: '', copiedAt: 0, page_context: null }),
            ];

            const streamErrorMsg = data.messages[1];
            const failedMsg = data.messages[2];
            data.rateLimit = { secondsLeft: 30, timerId: null, userMsg: failedMsg };

            await data.retryTurn(streamErrorMsg);

            return {
                totalCount: data.messages.length,
                roles: data.messages.map((m) => m.role),
                contents: data.messages.map((m) => m.content),
                rateLimitStillActive: data.rateLimit !== null,
            };
        })();
    JS);

    // Before the fix: retryTurn() spliced out the user+streamError pair
    // (removeCount 2), then sendMessage() bailed on the active rate limit,
    // leaving only the unrelated failed message behind: totalCount would
    // drop from 3 to 1.
    expect($result['totalCount'])->toBe(3);
    expect($result['roles'])->toBe(['user', 'assistant', 'user']);
    expect($result['contents'])->toBe(['first attempt', '', 'second attempt']);
    expect($result['rateLimitStillActive'])->toBeTrue();

    // Server-truth companion to the local-array assertions above. Unlike
    // regenerateMessage(), retryTurn() never calls supersedeServerTurns() at
    // all (the retried turn was never persisted server-side to begin with),
    // so the guard's server-side contract here is that nothing gets written:
    // no row for this conversation exists regardless of the local splice.
    expect(DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->count())->toBe(0);
});

it('does not permanently supersede the edited turn when saveEdit is triggered while an earlier send is still rate-limited (issue #499 sibling: saveEdit)', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A real persisted row for the turn being edited. saveEdit() supersedes
    // this row server-side BEFORE splicing local state (unlike retryTurn(),
    // and unlike regenerateMessage() post-#499-fix), so without a matching
    // real row the supersede anchor lookup finds nothing and the
    // `superseded_at is still null` assertion below would pass either way.
    $editedMessageId = (string) Str::uuid7();
    DB::table('agent_conversation_messages')->insert([
        'id' => $editedMessageId,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'test',
        'role' => 'user',
        'content' => 'first attempt',
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();
    $conversationIdJson = json_encode($conversationId);
    $editedMessageIdJson = json_encode($editedMessageId);

    // Fabricates the exact state the bug report describes: a countdown from a
    // PRIOR failed send is already ticking (this.rateLimit is set) when the
    // user edits and saves an EARLIER user message. saveEdit() supersedes the
    // server-side row FIRST, then splices this.messages, then calls
    // sendMessage() to resend, which silently no-ops on `if (this.rateLimit)
    // return`. Pre-fix that permanently deletes the edited turn (and
    // everything after it) both locally and server-side, with nothing sent
    // to replace it, and reload does not recover it.
    // Widened timeout, same reason as chatTriggerRateLimitedFailure above: unlike
    // the regenerate/retryTurn siblings (which never reach the network pre-fix
    // either), an UNFIXED saveEdit() makes a real supersede round trip before
    // returning, and a Pest-level retry re-invoking this whole script mid-flight
    // would fire that real POST twice against the same row.
    $result = Playwright::usingTimeout(60_000, fn (): mixed => $page->script(<<<JS
        (async () => {
            {$resolveInterface}

            window.Echo = null;
            data.conversationId = {$conversationIdJson};

            data.messages = [
                data.ensureClientKey({ id: {$editedMessageIdJson}, role: 'user', content: 'first attempt', editing: true, editText: 'edited while rate limited', sendState: 'sent', copiedAt: 0, page_context: null }),
                data.ensureClientKey({ role: 'assistant', content: 'first reply', pending_actions: [], paywall: null, sessionExpired: false, rendered: true, prerendered: true, copiedAt: 0, follow_ups: [] }),
                data.ensureClientKey({ role: 'user', content: 'second attempt', sendState: 'failed', editing: false, editText: '', copiedAt: 0, page_context: null }),
            ];

            const failedMsg = data.messages[data.messages.length - 1];
            data.rateLimit = { secondsLeft: 30, timerId: null, userMsg: failedMsg };

            // Captured BEFORE the call: pre-fix, saveEdit() splices this.messages
            // to empty, so data.messages[0] would be undefined afterward and
            // reading .editing off it would throw. This reference survives the
            // splice either way (splice removes it from the array, not from
            // existence), so it is the safe way to read the target's own state
            // regardless of which behavior actually ran.
            const targetMsg = data.messages[0];

            await data.saveEdit(targetMsg, 0);

            return {
                totalCount: data.messages.length,
                roles: data.messages.map((m) => m.role),
                contents: data.messages.map((m) => m.content),
                stillEditing: targetMsg.editing,
                rateLimitStillActive: data.rateLimit !== null,
            };
        })();
    JS));

    // Before the fix: saveEdit() has no rate-limit guard at all, so it runs
    // the full supersede-then-splice-then-resend sequence unconditionally.
    // totalCount would drop to 0 (splice(index) with index 0 removes
    // everything) and the server-side supersede would have already committed.
    expect($result['totalCount'])->toBe(3);
    expect($result['roles'])->toBe(['user', 'assistant', 'user']);
    expect($result['contents'])->toBe(['first attempt', 'first reply', 'second attempt']);
    expect($result['stillEditing'])->toBeTrue();
    expect($result['rateLimitStillActive'])->toBeTrue();

    // The server-truth assertion, and the one that actually matters: reload
    // reads from the DB, not the local array, and a superseded row is never
    // recovered by reload. This is what makes the bug permanent data loss
    // rather than a cosmetic glitch.
    $supersededAt = DB::table('agent_conversation_messages')
        ->where('id', $editedMessageId)
        ->value('superseded_at');

    expect($supersededAt)->toBeNull();
});

it('does not soft-lock the composer when subscribeToConversation throws on a non-first message', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    // A real, already-existing conversation so deliverMessage() takes the
    // non-first-message branch (the one whose channel-subscribe await used to
    // sit outside the try/catch).
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();
    $conversationIdJson = json_encode($conversationId);

    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            data.conversationId = {$conversationIdJson};

            // Simulates any synchronous throw inside subscribeToConversation
            // (window.Echo.private() throwing is a real-world example). Before
            // this fix, that await sat outside deliverMessage's try/catch on
            // this branch: the bubble would be stuck at 'sending' forever (no
            // Resend ever appears, gated on 'failed') and isStreaming would
            // never reset, silently queueing every later send.
            window.Echo = { private: () => { throw new Error('channel boom'); } };

            data.localEditor().setText('this must not soft-lock the composer');
            data.sendMessage();
            return true;
        })();
    JS);

    $failedState = chatPollUserSendState($page, $resolveInterface, 'failed');

    expect($failedState)->toBe('failed');

    $result = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            return {
                // The soft-lock: without the fix this stays true forever, and
                // every later sendMessage() call silently stashes into
                // queuedSend instead of actually sending.
                isStreaming: data.isStreaming,
                userCount: data.messages.filter((m) => m.role === 'user').length,
                domResendButtons: document.querySelectorAll('[data-user-bubble][data-send-state="failed"] [data-resend-button]').length,
            };
        })();
    JS);

    expect($result['isStreaming'])->toBeFalse();
    expect($result['userCount'])->toBe(1);
    expect($result['domResendButtons'])->toBe(1);
});

it('disables Regenerate/Edit and hides Retry while rate-limited, so the affordances are not dead clicks', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();
    $conversationIdJson = json_encode($conversationId);

    // Fabricates a transcript that exercises all three affordances at once: a
    // regenerate-eligible reply, an editable earlier user turn, and a
    // retryable failed turn, then arms the client-side rate-limit countdown
    // exactly as the other tests in this file do. isStreaming stays false
    // throughout: while streaming already hides all three via x-show
    // (:disabled="isStreaming" only ever guards an unreachable click), the
    // gap this closes is the rate-limited-but-not-streaming state, where
    // these previously rendered fully enabled and did nothing on click.
    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            window.Echo = null;
            data.conversationId = {$conversationIdJson};

            data.messages = [
                data.ensureClientKey({ role: 'user', content: 'editable message', sendState: 'sent', editing: false, editText: '', copiedAt: 0, page_context: null }),
                data.ensureClientKey({ role: 'assistant', content: 'a reply', pending_actions: [], paywall: null, sessionExpired: false, rendered: true, prerendered: true, copiedAt: 0, follow_ups: [] }),
                data.ensureClientKey({ role: 'user', content: 'second attempt', sendState: 'failed', editing: false, editText: '', copiedAt: 0, page_context: null }),
                data.ensureClientKey({ role: 'assistant', content: '', streamError: 'The assistant took too long to respond.', retryable: true, pending_actions: [], paywall: null, sessionExpired: false, rendered: true, prerendered: true, copiedAt: 0, follow_ups: [] }),
            ];

            const failedMsg = data.messages[2];
            data.rateLimit = { secondsLeft: 30, timerId: null, userMsg: failedMsg };
            return true;
        })();
    JS);

    $result = $page->script(<<<'JS'
        (() => {
            const editButton = document.querySelector('[data-edit-button]');
            const regenerateButton = document.querySelector('[data-regenerate-button]');
            const retryButton = document.querySelector('[data-retry-button]');

            return {
                editDisabled: editButton?.disabled ?? null,
                editTitle: editButton?.getAttribute('title') ?? null,
                regenerateDisabled: regenerateButton?.disabled ?? null,
                regenerateTitle: regenerateButton?.getAttribute('title') ?? null,
                retryHidden: retryButton ? (retryButton.offsetParent === null) : null,
            };
        })();
    JS);

    expect($result['editDisabled'])->toBeTrue();
    expect($result['editTitle'])->toContain('sending too fast');
    expect($result['regenerateDisabled'])->toBeTrue();
    expect($result['regenerateTitle'])->toContain('sending too fast');
    // Retry is x-show gated, not :disabled: pre-fix it stayed visible and
    // clickable throughout the rate-limited window.
    expect($result['retryHidden'])->toBeTrue();
});
