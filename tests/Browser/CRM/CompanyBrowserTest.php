<?php

declare(strict_types=1);

use App\Filament\Concerns\HasBoardViewSwitcher;
use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;

mutates(CompanyResource::class, HasBoardViewSwitcher::class);

it('can create a company through the browser', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/companies")
        ->press('New company')
        ->type('[id="mountedActionSchema0.name"]', 'Browser Test Corp')
        ->press('Create')
        ->assertSee('Browser Test Corp');

    expect(Company::where('name', 'Browser Test Corp')->where('team_id', $team->id)->exists())->toBeTrue();
});

it('paints the header action dropdown above the table toolbar', function (): void {
    $this->withVite();

    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/companies")
        ->assertSee('Import / Export');

    $page->script(<<<'JS'
        (() => {
            const trigger = document.querySelector('.fi-header-actions-ctn .fi-dropdown-trigger');
            trigger.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, button: 0 }));
            return true;
        })();
    JS);

    $page->assertSee('Export companies');

    $hitsPanel = $page->script(<<<'JS'
        (() => {
            const panel = document.querySelector('.fi-header-actions-ctn .fi-dropdown-panel');
            const box = panel.getBoundingClientRect();

            return ['left', 'right']
                .map((edge) => document.elementFromPoint(
                    edge === 'left' ? box.left + 8 : box.right - 8,
                    box.bottom - 8,
                ))
                .every((hit) => hit?.closest('.fi-dropdown-panel') === panel);
        })();
    JS);

    expect($hitsPanel)->toBeTrue();
});

it('keeps app page headings in the topbar across navigation and viewport sizes', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/companies")
        ->assertVisible('[data-page-heading]')
        ->assertSeeIn('[data-page-heading]', 'Companies')
        ->assertMissing('main h1')
        ->assertNoJavaScriptErrors();

    $page->click("a.fi-sidebar-item-btn[href$='/app/{$team->slug}/people']")
        ->assertPathIs("/app/{$team->slug}/people")
        ->assertVisible('[data-page-heading]')
        ->assertSeeIn('[data-page-heading]', 'People')
        ->assertMissing('main h1')
        ->resize(390, 844)
        ->assertVisible('[data-page-heading]')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.querySelector("[data-page-heading]").closest("nav")?.getAttribute("aria-label")'))
        ->toBe('Topbar');

    $spacing = $page->script(<<<'JS'
        (() => {
            const topbar = document.querySelector('.fi-topbar').getBoundingClientRect();
            const title = document.querySelector('[data-page-heading]').getBoundingClientRect();
            const actions = document.querySelector('main .fi-header-actions-ctn').getBoundingClientRect();
            const table = document.querySelector('.fi-ta-ctn').getBoundingClientRect();

            return {
                topbarHeight: topbar.height,
                titleCentered: Math.abs(topbar.top + topbar.height / 2 - title.top - title.height / 2) <= 0.5,
                actionsTopGap: actions.top - topbar.bottom,
                tableTopGap: table.top - actions.bottom,
                hasHorizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            };
        })();
    JS);

    expect($spacing)->toMatchArray([
        'topbarHeight' => 64,
        'titleCentered' => true,
        'actionsTopGap' => 32,
        'tableTopGap' => 32,
        'hasHorizontalOverflow' => false,
    ]);

    $page->resize(1440, 900)
        ->navigate("/app/{$team->slug}/tasks")
        ->click("a[href$='/app/{$team->slug}/tasks/board']")
        ->assertPathIs("/app/{$team->slug}/tasks/board")
        ->assertVisible('[data-page-heading]')
        ->assertSeeIn('[data-page-heading]', 'Tasks')
        ->assertVisible('.fi-board-header .fi-ta-search-field')
        ->assertNoJavaScriptErrors()
        ->assertScript('(() => document.querySelectorAll("[data-page-heading]").length === 1)()')
        ->assertScript('(() => document.querySelector("[data-page-heading] h1")?.textContent.trim() === "Tasks")()')
        ->assertScript('(() => document.querySelector("[data-page-heading] nav")?.parentElement.matches("[data-page-heading]") === true)()')
        ->assertScript('(() => !document.querySelector("main .fi-header-heading").getClientRects().length)()')
        ->click(".fi-topbar-start a[href$='/app/{$team->slug}/tasks']")
        ->assertPathIs("/app/{$team->slug}/tasks")
        ->assertScript('(() => document.querySelectorAll("[data-page-heading]").length === 1)()');
});
