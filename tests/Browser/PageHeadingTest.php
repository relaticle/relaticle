<?php

declare(strict_types=1);

use App\Models\User;

/**
 * The app panel shows the page title in the topbar instead of the page body, via
 * an x-teleport. The target sits inside the Topbar Livewire component, whose
 * morph removes children it did not render, and the inline <h1> is suppressed
 * for this panel, so losing the teleported node leaves the page with no heading
 * at all until a full navigation.
 */
it('keeps the page heading in the topbar across a topbar re-render', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/companies");

    $page->wait(1);

    $before = json_decode((string) $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('.fi-topbar-start [data-page-heading]');
            return JSON.stringify({ present: !!el, text: el ? el.innerText.trim() : null });
        })();
    JS), true);

    expect($before['present'])->toBeTrue()
        ->and($before['text'])->toBe('Companies');

    // Re-render the Topbar on its own, the way a tenant switch or a topbar
    // action does.
    $page->script(<<<'JS'
        (() => {
            const topbar = window.Livewire.all().find((c) => c.name && c.name.toLowerCase().includes('topbar'));
            const el = document.querySelector('[wire\\:id="' + topbar.id + '"]');
            el.__livewire.$wire.$refresh();
            return true;
        })();
    JS);

    $page->wait(2);

    $after = json_decode((string) $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('.fi-topbar-start [data-page-heading]');
            return JSON.stringify({
                present: !!el,
                text: el ? el.innerText.trim() : null,
                headings: Array.from(document.querySelectorAll('h1')).map((h) => h.innerText.trim()),
            });
        })();
    JS), true);

    expect($after['present'])->toBeTrue('the topbar morph dropped the teleported page heading')
        ->and($after['text'])->toBe('Companies')
        ->and($after['headings'])->toContain('Companies');
});

/**
 * The guard rail on the fix above, not a second bug. Alpine removes a teleported
 * node when its source page is destroyed, so this holds on its own today. It is
 * pinned because the obvious way to make a heading survive a topbar morph, keep
 * a reference to it and re-append it, quietly defeats that cleanup: the first
 * attempt at the fix above did exactly that and left the dashboard wearing the
 * title of whatever page you arrived from. The sync reads the current page's
 * template instead, so there is nothing to go stale.
 */
it('drops the previous page heading when the next page has none', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/companies");

    $page->wait(1);

    $heading = fn (): string => (string) $page->script(
        "document.querySelector('.fi-topbar-start [data-page-heading]')?.innerText.trim() ?? 'NONE'"
    );

    expect($heading())->toBe('Companies');

    // The real sidebar link, so this is a wire:navigate and not a full reload:
    // a reload would rebuild the topbar and hide the bug.
    $page->click('a.fi-sidebar-item-btn[href$="/app/'.$team->slug.'"]');
    $page->wait(2);

    expect($page->script('window.location.pathname'))->toBe("/app/{$team->slug}")
        ->and($heading())->toBe('NONE', 'the dashboard inherited the previous page title');
});
