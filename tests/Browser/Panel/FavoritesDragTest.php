<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\FavoriteFolder;
use App\Models\FavoriteRecord;
use App\Models\User;

it('drags a favorite into a folder and back out without opening the record', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $folder = FavoriteFolder::factory()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey(), 'name' => 'Q4 renewals', 'position' => 1]);
    $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Acme Corp']);
    $favorite = favoriteFor($user, $workspace, $company, 2);

    $row = '.fi-sidebar-favorites li[data-favorite-key^="record:"]';
    $folderRow = '.fi-sidebar-favorites-folder > .fi-sidebar-item';
    $inFolder = 'document.querySelector(\'[data-favorite-folder] li[data-favorite-key^="record:"]\') !== null';

    $page = visitAs($user, "/app/{$workspace->slug}", 1440, 900);
    $page->wait(0.5);
    $page->page()->locator($row)->dragTo($page->page()->locator('[data-favorite-folder-empty]'), ['steps' => 15]);

    $page->wait(1.5)->assertScript($inFolder, true)->assertPathIs("/app/{$workspace->slug}");

    expect($favorite->refresh()->favorite_folder_id)->toBe($folder->getKey());

    $page->page()->locator($row)->dragTo($page->page()->locator($folderRow), ['targetPosition' => ['x' => 60, 'y' => 2], 'steps' => 15]);

    $page->wait(1.5)->assertScript($inFolder, false)->assertPathIs("/app/{$workspace->slug}")->assertNoJavaScriptErrors();

    expect(FavoriteRecord::query()->whereKey($favorite->getKey())->value('favorite_folder_id'))->toBeNull();
});

it('opens a folder menu under its button', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $folder = FavoriteFolder::factory()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey(), 'name' => 'Q4 renewals', 'position' => 1]);
    favoriteFor($user, $workspace, Company::factory()->recycle([$user, $workspace])->create(), 1, $folder);

    $panelUnderButton = <<<'JS'
        (() => {
            const button = document.querySelector('.fi-sidebar-favorites-folder [aria-label="Folder options"]').getBoundingClientRect();
            const panel = [...document.querySelectorAll('.fi-dropdown-panel')].find((p) => p.textContent.includes('Rename') && getComputedStyle(p).display !== 'none');
            const box = panel.getBoundingClientRect();
            return Math.abs(box.top - button.bottom) < 16 && Math.abs(box.right - button.right) < 16 && document.elementFromPoint(box.left + box.width / 2, box.top + 12).closest('.fi-dropdown-panel') === panel;
        })()
        JS;

    $page = visitAs($user, "/app/{$workspace->slug}", 1440, 900);
    $page->hover('.fi-sidebar-favorites-folder > .fi-sidebar-item')
        ->click('.fi-sidebar-favorites-folder [aria-label="Folder options"]')
        ->wait(0.5)
        ->assertScript($panelUnderButton, true)
        ->click('Rename')
        ->assertSee(__('favorites.folders.name'))
        ->assertNoJavaScriptErrors();
});
