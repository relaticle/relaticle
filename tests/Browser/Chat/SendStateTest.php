<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
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
 */
const CHAT_WARM_UP_LIMITER_JS = <<<'JS'
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const warmUpLimiter = async () => {
        for (let i = 0; i < 10; i++) {
            await fetch('/chat/conversations', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ document: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'warmup ' + i }] }] } }),
            });
        }
    };
    JS;

it('carries the optimistic bubble through sending then sent on a real send', function (): void {
    Queue::fake();

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

    // Echo is stubbed out so the round trip does not wait on a real Reverb
    // channel subscription: this test is only about the fetch-driven
    // sendState transition, not the streaming pipeline.
    $result = $page->script(<<<JS
        (async () => {
            {$resolveInterface}

            window.Echo = null;
            data.localEditor().setText('hello state machine');
            data.sendMessage();

            const sendingState = data.messages.find((m) => m.role === 'user')?.sendState;

            let finalState = null;
            for (let i = 0; i < 100; i++) {
                await new Promise((r) => setTimeout(r, 50));
                const msg = data.messages.find((m) => m.role === 'user');
                if (msg && msg.sendState === 'sent') { finalState = msg.sendState; break; }
            }

            // One more tick so Alpine's reactive :data-send-state binding has
            // flushed into the DOM before we query it.
            await new Promise((r) => setTimeout(r, 100));

            return {
                sendingState,
                finalState,
                domSentBubbles: document.querySelectorAll('[data-user-bubble][data-send-state="sent"]').length,
            };
        })();
    JS);

    expect($result['sendingState'])->toBe('sending');
    expect($result['finalState'])->toBe('sent');
    expect($result['domSentBubbles'])->toBe(1);
});

it('marks an optimistic bubble failed on rate limit without duplicating it, and resend recovers', function (): void {
    Queue::fake();

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
    $warmUp = CHAT_WARM_UP_LIMITER_JS;

    $afterFailure = $page->script(<<<JS
        (async () => {
            {$resolveInterface}
            {$warmUp}

            window.Echo = null;
            await warmUpLimiter();

            data.localEditor().setText('rate limited message');
            data.sendMessage();

            let sawFailed = false;
            for (let i = 0; i < 100; i++) {
                await new Promise((r) => setTimeout(r, 50));
                const msg = data.messages.find((m) => m.role === 'user');
                if (msg && msg.sendState === 'failed') { sawFailed = true; break; }
            }

            // One more tick so Alpine's reactive :data-send-state binding has
            // flushed into the DOM before we query it.
            await new Promise((r) => setTimeout(r, 100));

            const failedMsg = data.messages.find((m) => m.role === 'user');

            return {
                sawFailed,
                userCount: data.messages.filter((m) => m.role === 'user').length,
                domUserBubbles: document.querySelectorAll('[data-user-bubble]').length,
                domFailedBubbles: document.querySelectorAll('[data-user-bubble][data-send-state="failed"]').length,
                clientKey: failedMsg?.clientKey ?? null,
            };
        })();
    JS);

    expect($afterFailure['sawFailed'])->toBeTrue();
    expect($afterFailure['userCount'])->toBe(1);
    expect($afterFailure['domUserBubbles'])->toBe(1);
    expect($afterFailure['domFailedBubbles'])->toBe(1);
    expect($afterFailure['clientKey'])->not->toBeNull();

    // Fast-forward past the one-minute decay window instead of sleeping:
    // Carbon's test-now is visible to the browser-serving execution context.
    $this->travel(61)->seconds();

    $afterResend = $page->script(<<<JS
        (async () => {
            {$resolveInterface}

            const failedMsg = data.messages.find((m) => m.role === 'user' && m.sendState === 'failed');
            const clientKeyBeforeResend = failedMsg.clientKey;
            data.resendMessage(failedMsg);

            let finalState = null;
            for (let i = 0; i < 100; i++) {
                await new Promise((r) => setTimeout(r, 50));
                const msg = data.messages.find((m) => m.role === 'user');
                if (msg && msg.sendState === 'sent') { finalState = msg.sendState; break; }
            }

            // One more tick so Alpine's reactive :data-send-state binding has
            // flushed into the DOM before we query it.
            await new Promise((r) => setTimeout(r, 100));

            const resentMsg = data.messages.find((m) => m.role === 'user');

            return {
                finalState,
                userCount: data.messages.filter((m) => m.role === 'user').length,
                domUserBubbles: document.querySelectorAll('[data-user-bubble]').length,
                sameClientKey: resentMsg?.clientKey === clientKeyBeforeResend,
            };
        })();
    JS);

    expect($afterResend['finalState'])->toBe('sent');
    expect($afterResend['userCount'])->toBe(1);
    expect($afterResend['domUserBubbles'])->toBe(1);
    expect($afterResend['sameClientKey'])->toBeTrue();
});

it('keeps the transcript non-empty when a regenerate resend hits the rate limit (issue #499)', function (): void {
    Queue::fake();

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
    $warmUp = CHAT_WARM_UP_LIMITER_JS;

    $result = $page->script(<<<JS
        (async () => {
            {$resolveInterface}
            {$warmUp}

            window.Echo = null;

            // Fabricate an existing turn (user + rendered assistant reply) as the
            // regenerate target, so regenerateMessage(1) has a real preceding
            // user message to resend.
            data.messages = [
                data.ensureClientKey({ role: 'user', content: 'first attempt', sendState: 'sent', editing: false, editText: '', copiedAt: 0, page_context: null }),
                data.ensureClientKey({ role: 'assistant', content: 'first reply', pending_actions: [], paywall: null, sessionExpired: false, rendered: true, prerendered: true, copiedAt: 0, follow_ups: [] }),
            ];

            await warmUpLimiter();

            data.regenerateMessage(1);

            let done = false;
            for (let i = 0; i < 100 && !done; i++) {
                await new Promise((r) => setTimeout(r, 50));
                const msg = data.messages.find((m) => m.role === 'user');
                done = !!(msg && msg.sendState === 'failed');
            }

            return {
                totalCount: data.messages.length,
                userCount: data.messages.filter((m) => m.role === 'user').length,
                failedCount: data.messages.filter((m) => m.sendState === 'failed').length,
            };
        })();
    JS);

    expect($result['totalCount'])->toBe(1);
    expect($result['userCount'])->toBe(1);
    expect($result['failedCount'])->toBe(1);
});
