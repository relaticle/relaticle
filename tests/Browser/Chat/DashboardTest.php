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
