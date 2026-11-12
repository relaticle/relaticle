<?php

declare(strict_types=1);

use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;

mutates(CompanyResource::class, HasViewSwitcher::class);

it('can create a company through the browser', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies")
        ->press('New company')
        ->type('[id="mountedActionSchema0.name"]', 'Browser Test Corp')
        ->press('Create')
        ->assertSee('Browser Test Corp');

    expect(Company::where('name', 'Browser Test Corp')->where('workspace_id', $workspace->id)->exists())->toBeTrue();
});

it('paints the header action dropdown above the table toolbar', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies")
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
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies")
        ->assertVisible('[data-page-heading]')
        ->assertSeeIn('[data-page-heading]', 'Companies')
        ->assertMissing('main h1')
        ->assertNoJavaScriptErrors();

    $page->click("a.fi-sidebar-item-btn[href$='/app/{$workspace->slug}/people']")
        ->assertPathIs("/app/{$workspace->slug}/people")
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
        'topbarHeight' => 48,
        'titleCentered' => true,
        'actionsTopGap' => 12,
        'tableTopGap' => 13,
        'hasHorizontalOverflow' => false,
    ]);

    $page->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/tasks")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click(".fi-view-switcher a[href$='/app/{$workspace->slug}/tasks/board']")
        ->assertPathIs("/app/{$workspace->slug}/tasks/board")
        ->assertVisible('[data-page-heading]')
        ->assertSeeIn('[data-page-heading]', 'Tasks')
        ->assertVisible('.fi-board-header [data-filter-bar]')
        ->assertNoJavaScriptErrors()
        ->assertScript('(() => document.querySelectorAll("[data-page-heading]").length === 1)()')
        ->assertScript('(() => document.querySelector("[data-page-heading] h1")?.textContent.trim() === "Tasks")()')
        ->assertVisible('.fi-board-header .fi-view-switcher')
        ->assertScript('(() => document.querySelector("[data-page-heading] .fi-view-switcher") === null)()')
        ->assertScript('(() => [...document.querySelectorAll("main .fi-header-heading")].every((heading) => ! heading.getClientRects().length))()')
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click(".fi-view-switcher a[href$='/app/{$workspace->slug}/tasks?view=all']")
        ->assertPathIs("/app/{$workspace->slug}/tasks")
        ->assertScript('(() => document.querySelectorAll("[data-page-heading]").length === 1)()');

    $page->resize(390, 844)
        ->assertVisible('.fi-view-switcher .fi-dropdown-trigger button[aria-label]')
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click(".fi-view-switcher a[href$='/app/{$workspace->slug}/tasks/board']")
        ->assertPathIs("/app/{$workspace->slug}/tasks/board")
        ->assertNoJavaScriptErrors();
});

it('keeps the header row and its controls in place when switching between the list and the board', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $headerRow = <<<'JS'
        (() => {
            const row = document.querySelector('.fi-board-header-row') ?? document.querySelector('main .fi-header');
            const controlTops = [...row.querySelectorAll('button, input')]
                .filter((control) => control.getClientRects().length > 0)
                .map((control) => control.getBoundingClientRect().top);

            return { height: row.getBoundingClientRect().height, controlTops: [...new Set(controlTops)] };
        })();
    JS;

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/opportunities?view=all")
        ->assertVisible('main .fi-header .fi-view-switcher');

    $list = $page->script($headerRow);

    $page->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click(".fi-view-switcher a[href$='/app/{$workspace->slug}/opportunities/board']")
        ->assertPathIs("/app/{$workspace->slug}/opportunities/board")
        ->assertVisible('.fi-board-header [data-filter-bar]');

    $board = $page->script($headerRow);

    expect($list['height'])->toBe(57)
        ->and($board['height'])->toBe(57)
        ->and($list['controlTops'])->toHaveCount(1)
        ->and($board['controlTops'])->toBe($list['controlTops']);
});
