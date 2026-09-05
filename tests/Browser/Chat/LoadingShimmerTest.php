<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Agents\CrmAssistant;
use Tests\Helpers\ChatBrowser;

it('renders a single shimmer indicator with default label when streaming starts and no tool is running', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
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

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
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
                { role: 'assistant', content: '', pending_actions: [], paywall: null, sessionExpired: false, rendered: false, prerendered: false },
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

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$team->slug}")
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
                { role: 'assistant', content: 'hello back', pending_actions: [], paywall: null, sessionExpired: false, rendered: false, prerendered: false },
            ];
            return true;
        })();
    JS);

    $shimmerCount = (int) $page->script(<<<'JS'
        (() => document.querySelectorAll('[data-chat-loading-indicator]').length)();
    JS);

    expect($shimmerCount)->toBe(0);
});

/**
 * Every tool the assistant can call needs a human label. Anything the label map
 * misses used to fall through to the raw wire name, so the shimmer read
 * "Running add_custom_field_options…" at the user. Driven off the agent's own
 * tool list, so registering a tool without a label fails here.
 */
it('renders a human label for every tool the assistant can call', function (): void {
    $toolNames = array_map(
        fn (object $tool): string => class_basename($tool),
        app(CrmAssistant::class)->tools(),
    );

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = ChatBrowser::logIn($user, $team->slug)
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();
    $names = json_encode($toolNames, JSON_THROW_ON_ERROR);

    $labels = $page->script(<<<JS
        (() => {
            {$resolveInterface}

            return {$names}.map((name) => data.friendlyToolStatus(name));
        })();
    JS);

    expect($labels)->toHaveCount(count($toolNames));

    foreach ($toolNames as $index => $toolName) {
        $label = $labels[$index];

        expect($label)->toBeString()
            // A raw identifier is the exact defect: snake_case, or the
            // "Running :name…" fallback that only unmapped tools reach.
            ->and($label)->not->toContain('_')
            ->and($label)->not->toStartWith('Running ')
            ->and(mb_strtolower($label))->not->toContain(mb_strtolower($toolName));
    }

    // Spot-check the two ends: a CRUD tool and one of the tools that used to
    // have no mapping at all.
    $byName = array_combine($toolNames, $labels);
    expect($byName['ListCompaniesTool'])->toBe('Searching companies…')
        ->and($byName['AddCustomFieldOptionsTool'])->toBe('Preparing new field options…')
        ->and($byName['SearchDocsTool'])->toBe('Searching the documentation…');
});

/**
 * A tool that ships before its label still must not leak an identifier: the
 * fallback reads it back as words.
 */
it('reads an unmapped tool name back as words instead of an identifier', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = ChatBrowser::logIn($user, $team->slug)
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $label = $page->script(<<<JS
        (() => {
            {$resolveInterface}

            return data.friendlyToolStatus('ForecastPipelineHealthTool');
        })();
    JS);

    expect($label)->toBe('Running forecast pipeline health…');
});
