<?php

declare(strict_types=1);

use App\Livewire\App\AccessTokens\ManageAccessTokens;
use App\Models\User;
use Illuminate\Support\Str;

mutates(ManageAccessTokens::class);

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
