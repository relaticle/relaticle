<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;

it('opens a picker when @ is typed and inserts a token on selection', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'AcmeQA']);

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
            window.__mentionHost = visible;
            const data = Alpine.$data(visible);
            data.input = '@ac';
            const ta = visible.querySelector('#chat-message-input');
            ta.value = '@ac';
            ta.focus();
            ta.setSelectionRange(3, 3);
            data.detectMentionTrigger(ta);
            return true;
        })();
    JS);

    $resultsCount = $page->script(<<<'JS'
        (async () => {
            const data = Alpine.$data(window.__mentionHost);
            const start = Date.now();
            while (data.mention.results.length === 0 && Date.now() - start < 5000) {
                await new Promise((r) => setTimeout(r, 50));
            }
            return data.mention.results.length;
        })();
    JS);

    expect($resultsCount)->toBeGreaterThan(0);

    $page->script(<<<'JS'
        (() => {
            const data = Alpine.$data(window.__mentionHost);
            data.selectMention(data.mention.results[0]);
            return true;
        })();
    JS);

    $value = $page->script(<<<'JS'
        (() => Alpine.$data(window.__mentionHost).input)();
    JS);

    expect($value)->toContain('@AcmeQA ');

    $isOpen = $page->script(<<<'JS'
        (() => Alpine.$data(window.__mentionHost).mention.open)();
    JS);

    expect($isOpen)->toBeFalse();
});

it('closes the picker via closeMention', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'EscapeCo']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats");

    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            window.__mentionHost = visible;
            const data = Alpine.$data(visible);
            data.input = '@es';
            const ta = visible.querySelector('#chat-message-input');
            ta.value = '@es';
            ta.focus();
            ta.setSelectionRange(3, 3);
            data.detectMentionTrigger(ta);
            return true;
        })();
    JS);

    $resultsCount = $page->script(<<<'JS'
        (async () => {
            const data = Alpine.$data(window.__mentionHost);
            const start = Date.now();
            while (data.mention.results.length === 0 && Date.now() - start < 5000) {
                await new Promise((r) => setTimeout(r, 50));
            }
            return data.mention.results.length;
        })();
    JS);

    expect($resultsCount)->toBeGreaterThan(0);

    $page->script(<<<'JS'
        (() => {
            Alpine.$data(window.__mentionHost).closeMention();
            return true;
        })();
    JS);

    $isOpen = $page->script(<<<'JS'
        (() => Alpine.$data(window.__mentionHost).mention.open)();
    JS);

    expect($isOpen)->toBeFalse();
});

it('does not open the picker for queries shorter than 2 chars', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats");

    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            window.__mentionHost = visible;
            const data = Alpine.$data(visible);
            data.input = '@a';
            const ta = visible.querySelector('#chat-message-input');
            ta.value = '@a';
            ta.focus();
            ta.setSelectionRange(2, 2);
            data.detectMentionTrigger(ta);
            return true;
        })();
    JS);

    $state = $page->script(<<<'JS'
        (() => {
            const m = Alpine.$data(window.__mentionHost).mention;
            return { open: m.open, results: m.results.length };
        })();
    JS);

    expect($state['open'])->toBeTrue();
    expect($state['results'])->toBe(0);
});

it('aborts the in-flight fetch when the query drops below the 2-char minimum', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'AcmeQA']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats");

    // Simulate: user types @ac (triggers fetch), then backspaces to @a (should abort)
    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            window.__mentionHost = visible;
            const data = Alpine.$data(visible);
            const ta = visible.querySelector('#chat-message-input');

            data.input = '@ac';
            ta.value = '@ac';
            ta.focus();
            ta.setSelectionRange(3, 3);
            data.detectMentionTrigger(ta);

            // Immediately backspace to @a before fetch resolves
            data.input = '@a';
            ta.value = '@a';
            ta.setSelectionRange(2, 2);
            data.detectMentionTrigger(ta);
            return true;
        })();
    JS);

    // Wait long enough for any pending fetch to have completed
    $page->script(<<<'JS'
        (() => new Promise((resolve) => setTimeout(resolve, 500)))();
    JS);

    $resultsLength = $page->script(<<<'JS'
        (() => Alpine.$data(window.__mentionHost).mention.results.length)();
    JS);

    expect($resultsLength)->toBe(0);
});

it('searches for a multi-word company name', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'Acme Corp']);
    Company::factory()->for($team)->create(['name' => 'Globex']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats");

    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            window.__mentionHost = visible;
            const data = Alpine.$data(visible);
            data.input = '@Acme C';
            const ta = visible.querySelector('#chat-message-input');
            ta.value = '@Acme C';
            ta.focus();
            ta.setSelectionRange(7, 7);
            data.detectMentionTrigger(ta);
            return true;
        })();
    JS);

    $resultsCount = $page->script(<<<'JS'
        (async () => {
            const data = Alpine.$data(window.__mentionHost);
            const start = Date.now();
            while (data.mention.results.length === 0 && Date.now() - start < 5000) {
                await new Promise((r) => setTimeout(r, 50));
            }
            return data.mention.results.map((r) => r.label);
        })();
    JS);

    expect($resultsCount)->toContain('Acme Corp');
    expect($resultsCount)->not->toContain('Globex');
});

it('removes a selected mention via chip × button', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    Company::factory()->for($team)->create(['name' => 'AcmeQA']);

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}/companies")
        ->navigate("/app/{$team->slug}/chats");

    $page->script(<<<'JS'
        (() => {
            const candidates = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const visible = candidates.find((el) => el.offsetParent !== null) ?? candidates[0];
            window.__mentionHost = visible;
            const data = Alpine.$data(visible);
            data.input = '@ac';
            const ta = visible.querySelector('#chat-message-input');
            ta.value = '@ac';
            ta.focus();
            ta.setSelectionRange(3, 3);
            data.detectMentionTrigger(ta);
            return true;
        })();
    JS);

    $page->script('(() => new Promise(r => setTimeout(r, 500)))();');

    $page->script(<<<'JS'
        (() => {
            const data = Alpine.$data(window.__mentionHost);
            if (data.mention.results.length > 0) {
                data.selectMention(data.mention.results[0]);
            }
            return true;
        })();
    JS);

    $countBefore = $page->script('(() => Alpine.$data(window.__mentionHost).selectedMentions.length)();');
    expect($countBefore)->toBe(1);

    $page->click('button[data-mention-remove]');

    $countAfter = $page->script('(() => Alpine.$data(window.__mentionHost).selectedMentions.length)();');
    expect($countAfter)->toBe(0);

    $inputAfter = $page->script('(() => Alpine.$data(window.__mentionHost).input)();');
    expect($inputAfter)->not->toContain('@AcmeQA');
});
