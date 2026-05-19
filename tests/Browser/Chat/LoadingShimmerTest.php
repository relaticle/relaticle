<?php

declare(strict_types=1);

use App\Models\User;

it('renders a single shimmer indicator with default label when streaming starts and no tool is running', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            window.__shimmerHost = visible;
            const data = Alpine.$data(visible);
            data.isStreaming = true;
            data.currentToolStatus = null;
            data.messages = [];
            return true;
        })();
    JS);

    $shimmerCount = (int) $page->script(<<<'JS'
        (() => document.querySelectorAll('[data-chat-loading-indicator]').length)();
    JS);

    $shimmerLabel = $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-chat-loading-indicator] [data-chat-loading-label]');
            return el ? el.textContent.trim() : null;
        })();
    JS);

    expect($shimmerCount)->toBe(1)
        ->and($shimmerLabel)->toBe('Thinking…');
});

it('updates the shimmer label when a tool call is in progress', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            const data = Alpine.$data(visible);
            data.isStreaming = true;
            data.currentToolStatus = 'Searching companies…';
            data.messages = [
                { role: 'user', content: 'find acme' },
                { role: 'assistant', content: '', pending_actions: [], paywall: null, sessionExpired: false, rendered: false, prerendered: false, copiedAt: 0, follow_ups: [] },
            ];
            return true;
        })();
    JS);

    $shimmerCount = (int) $page->script(<<<'JS'
        (() => document.querySelectorAll('[data-chat-loading-indicator]').length)();
    JS);

    $shimmerLabel = $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-chat-loading-indicator] [data-chat-loading-label]');
            return el ? el.textContent.trim() : null;
        })();
    JS);

    expect($shimmerCount)->toBe(1)
        ->and($shimmerLabel)->toBe('Searching companies…');
});

it('removes the shimmer once content arrives in the latest assistant message', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            const data = Alpine.$data(visible);
            data.isStreaming = true;
            data.currentToolStatus = null;
            data.messages = [
                { role: 'user', content: 'hi' },
                { role: 'assistant', content: 'hello back', pending_actions: [], paywall: null, sessionExpired: false, rendered: false, prerendered: false, copiedAt: 0, follow_ups: [] },
            ];
            return true;
        })();
    JS);

    $shimmerCount = (int) $page->script(<<<'JS'
        (() => document.querySelectorAll('[data-chat-loading-indicator]').length)();
    JS);

    expect($shimmerCount)->toBe(0);
});
