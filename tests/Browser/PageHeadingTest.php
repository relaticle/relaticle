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
