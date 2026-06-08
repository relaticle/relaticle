<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;

/**
 * Mentions are powered by a TipTap suggestion plugin (chat-mention-suggestion.js):
 * typing "@" + >=2 chars renders a popup (role="listbox", aria-label
 * "Mention suggestions") appended to <body>, each result is a
 * button[role="option"], and picking one inserts an atomic mention chip
 * (span[data-mention-id]) into the contenteditable composer.
 *
 * These tests drive the real editor with keystrokes rather than poking Alpine
 * internals, because the suggestion only fires from genuine ProseMirror input.
 */

/** Selector for the visible conversation composer's contenteditable surface. */
const EDITOR = '[data-chat-context="conversation"] [contenteditable="true"]';

/** Poll until the mention popup renders at least one option, returning its labels. */
const WAIT_FOR_OPTIONS = <<<'JS'
    (async () => {
        const start = Date.now();
        while (Date.now() - start < 5000) {
            const popup = document.querySelector('[role="listbox"][aria-label="Mention suggestions"]');
            const options = popup ? popup.querySelectorAll('button[role="option"]') : [];
            if (options.length > 0) {
                return Array.from(options).map((o) => o.textContent.trim());
            }
            await new Promise((r) => setTimeout(r, 50));
        }
        return [];
    })()
JS;

it('opens a picker when @ is typed and inserts a chip on selection', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'AcmeQA']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click(EDITOR)->keys(EDITOR, ['@', 'A', 'c']);

    $label = $page->script(<<<'JS'
        (async () => {
            const start = Date.now();
            let option = null;
            while (Date.now() - start < 5000) {
                const popup = document.querySelector('[role="listbox"][aria-label="Mention suggestions"]');
                option = popup?.querySelector('button[role="option"]');
                if (option) break;
                await new Promise((r) => setTimeout(r, 50));
            }
            if (! option) return null;
            const text = option.textContent.trim();
            option.click();
            return text;
        })();
    JS);

    expect($label)->toContain('AcmeQA');

    $chip = $page->script(<<<'JS'
        (() => {
            const node = document.querySelector('[data-chat-context="conversation"] [contenteditable="true"] span[data-mention-id]');
            return node ? node.textContent.trim() : null;
        })();
    JS);

    expect($chip)->toContain('@AcmeQA');
});

it('does not open the picker for queries shorter than 2 chars', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'AcmeQA']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click(EDITOR)->keys(EDITOR, ['@', 'a']);

    $popupVisible = $page->script(<<<'JS'
        (async () => {
            await new Promise((r) => setTimeout(r, 400));
            return !! document.querySelector('[role="listbox"][aria-label="Mention suggestions"]');
        })();
    JS);

    expect($popupVisible)->toBeFalse();
});

it('closes the picker when Escape is pressed', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'EscapeCo']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click(EDITOR)->keys(EDITOR, ['@', 'E', 's']);

    $opened = $page->script(WAIT_FOR_OPTIONS);
    expect($opened)->not->toBeEmpty();

    $page->keys(EDITOR, ['Escape']);

    $stillOpen = $page->script(<<<'JS'
        (async () => {
            await new Promise((r) => setTimeout(r, 200));
            return !! document.querySelector('[role="listbox"][aria-label="Mention suggestions"]');
        })();
    JS);

    expect($stillOpen)->toBeFalse();
});

it('closes the picker when the query drops below the 2-char minimum', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'AcmeQA']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click(EDITOR)->keys(EDITOR, ['@', 'A', 'c']);

    $opened = $page->script(WAIT_FOR_OPTIONS);
    expect($opened)->not->toBeEmpty();

    // Backspace back down to "@A" (one char) — the suggestion must close.
    $page->keys(EDITOR, ['Backspace']);

    $stillOpen = $page->script(<<<'JS'
        (async () => {
            await new Promise((r) => setTimeout(r, 300));
            return !! document.querySelector('[role="listbox"][aria-label="Mention suggestions"]');
        })();
    JS);

    expect($stillOpen)->toBeFalse();
});

it('searches across a multi-word company name', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'Acme Corp']);
    Company::factory()->for($team)->create(['name' => 'Globex']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    // allowSpaces is enabled, so "@Acme C" stays a single mention query.
    $page->click(EDITOR)->keys(EDITOR, ['@', 'A', 'c', 'm', 'e', ' ', 'C']);

    $labels = $page->script(WAIT_FOR_OPTIONS);

    expect(implode('|', $labels))->toContain('Acme Corp');
    expect(implode('|', $labels))->not->toContain('Globex');
});

it('removes a selected mention chip with backspace', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'AcmeQA']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->click(EDITOR)->keys(EDITOR, ['@', 'A', 'c']);

    $chipCount = $page->script(<<<'JS'
        (async () => {
            const start = Date.now();
            let option = null;
            while (Date.now() - start < 5000) {
                const popup = document.querySelector('[role="listbox"][aria-label="Mention suggestions"]');
                option = popup?.querySelector('button[role="option"]');
                if (option) break;
                await new Promise((r) => setTimeout(r, 50));
            }
            if (! option) return -1;
            option.click();
            await new Promise((r) => setTimeout(r, 100));
            return document.querySelectorAll('[data-chat-context="conversation"] [contenteditable="true"] span[data-mention-id]').length;
        })();
    JS);

    expect($chipCount)->toBe(1);

    // A trailing space follows the inserted chip; two backspaces remove the
    // space then the whole chip atomically (no per-character "@AcmeQ" residue).
    $page->keys(EDITOR, ['Backspace', 'Backspace']);

    $after = $page->script(<<<'JS'
        (() => {
            const editor = document.querySelector('[data-chat-context="conversation"] [contenteditable="true"]');
            return {
                chips: editor.querySelectorAll('span[data-mention-id]').length,
                text: editor.textContent,
            };
        })();
    JS);

    expect($after['chips'])->toBe(0);
    expect($after['text'])->not->toContain('Acme');
});
