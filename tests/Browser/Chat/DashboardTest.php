<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\User;

mutates(Dashboard::class);

it('can load the dashboard with chat input', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSourceHas('placeholder="Ask anything..."');
});

it('uses a white composer surface in light mode', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertNoJavaScriptErrors();

    $colors = json_decode((string) $page->script(<<<'JS'
        (() => {
            const root = document.documentElement;
            const composer = document.querySelector('[data-chat-context=dashboard] [data-chat-composer]');
            const startedDark = root.classList.contains('dark');

            root.classList.remove('dark');
            const light = getComputedStyle(composer).backgroundColor;
            root.classList.add('dark');
            const dark = getComputedStyle(composer).backgroundColor;
            root.classList.toggle('dark', startedDark);

            return JSON.stringify({ light, dark });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($colors['light'])->toBe('rgb(255, 255, 255)')
        ->and($colors['dark'])->not->toBe($colors['light']);
});

it('shows greeting on the dashboard', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->assertSee('Good');
});

/**
 * The ask_rela row moved with the checklist into the sidebar, where a
 * dashboard-only compose event would have been a dead click on every other
 * page. It now navigates to the chat page with `?prompt=`, which seeds that
 * page's composer and stops: sending stays the user's decision.
 *
 * A factory workspace holds no records, so the row seeds the empty-workspace
 * question -- asking a record-less workspace about its pipeline costs a tool
 * round-trip to report zero.
 */
it('seeds the chat composer from the ask_rela checklist step without sending', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->click('[data-step="ask_rela"] a')
        ->assertPathBeginsWith("/app/{$team->slug}/chats")
        // TipTap mounts on a tick after navigation, so reading getText()
        // immediately returns the empty editor rather than the seeded one.
        ->waitForText(__('filament/pages/dashboard.activation.steps.ask_rela.prompt_empty'))
        ->assertNoJavaScriptErrors();

    $state = json_decode((string) $page->script(<<<'JS'
        (() => {
            const editor = Alpine.$data(document.querySelector('[x-data*="chatEditor"]'));
            const messages = document.querySelectorAll('[data-testid="chat-message"]');

            return JSON.stringify({ text: editor.getText(), messageCount: messages.length });
        })();
    JS), true, 512, JSON_THROW_ON_ERROR);

    expect($state['text'])->toBe(__('filament/pages/dashboard.activation.steps.ask_rela.prompt_empty'))
        ->and($state['messageCount'])->toBe(0);
});
