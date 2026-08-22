<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Models\Company;
use App\Models\User;

mutates(CompanyResource::class);

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
});
