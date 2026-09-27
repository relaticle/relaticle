<?php

declare(strict_types=1);

use App\Livewire\App\AccessTokens\CreateAccessToken;
use App\Livewire\App\AccessTokens\ManageAccessTokens;
use App\Models\User;
use Illuminate\Support\Str;

mutates(CreateAccessToken::class, ManageAccessTokens::class);

it('copies a newly created access token to the clipboard', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->assertSee('New task')
        ->navigate("/app/{$workspace->slug}/settings/access-tokens")
        ->assertPathIs("/app/{$workspace->slug}/settings/access-tokens")
        ->waitForText('Create Access Token')
        ->assertScript('document.getElementById("form.name")?._x_model !== undefined')
        ->type('[id="form.name"]', 'MCP integration')
        ->click('button[wire\\:click="createToken"]')
        ->waitForText('Please copy your new access token.');

    $copied = $page->script(<<<'JS'
        (async () => {
            let copied = null;
            Object.defineProperty(navigator, 'clipboard', {
                configurable: true,
                value: { writeText: (text) => { copied = text; return Promise.resolve(); } },
            });

            const token = document.querySelector('input[readonly]').value;
            document.querySelector('button[x-on\\:click*="plainTextToken"]').click();
            await new Promise((resolve) => setTimeout(resolve, 0));

            return token.length > 0 && copied === token;
        })()
        JS);

    expect($copied)->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('scrolls a table wider than its card so the row actions stay reachable', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $user->tokens()->create([
        'name' => 'Deploy script',
        'token' => Str::random(40),
        'abilities' => ['create', 'read'],
        'workspace_id' => $workspace->id,
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/settings/access-tokens")
        ->resize(1280, 900)
        ->assertSee('Deploy script');

    $rowActionReachable = $page->script(<<<'JS'
        (() => {
            const card = [...document.querySelectorAll('.fi-ta-ctn')].find((table) => table.textContent.includes('Deploy script'))
            const scroller = card.querySelector('.fi-ta-content-ctn')

            scroller.scrollLeft = scroller.scrollWidth

            const action = card.querySelector('tbody tr td:last-child button')
            const box = action.getBoundingClientRect()
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2)

            return box.right <= card.getBoundingClientRect().right && action.contains(hit)
        })()
        JS);

    expect($rowActionReachable)->toBeTrue();
});
