<?php

declare(strict_types=1);

use App\Filament\Components\Tables\LinkedRecordsColumn;
use App\Models\Company;
use App\Models\Note;
use App\Models\People;
use App\Models\User;

mutates(LinkedRecordsColumn::class);

it('lists every linked record from the count in the relations column and closes without opening the row', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $note = Note::factory()->recycle([$user, $workspace])->create(['title' => 'Quarterly review']);
    $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Northwind Traders']);
    $note->companies()->attach($company);
    $note->people()->attach(People::factory()->count(3)->recycle([$user, $workspace])->create());

    $shown = "[...document.querySelectorAll('.fi-linked-records-cell-panel')].filter((panel) => panel._x_isShown).length";
    $links = "[...document.querySelectorAll('.fi-linked-records-cell-panel a')].filter((link) => link.offsetParent !== null).length";

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/notes/list")
        ->waitForText('Quarterly review')
        ->assertSeeIn('.fi-linked-records-cell > a', 'Northwind Traders')
        ->assertSeeIn('.fi-linked-records-cell-more', '+3')
        ->assertScript("document.querySelector('.fi-linked-records-cell > a').getAttribute('href').endsWith('/companies/{$company->getKey()}')", true)
        ->click('.fi-linked-records-cell-more')
        ->assertScript($shown, 1)
        ->assertScript($links, 4)
        ->assertScript("document.querySelector('.fi-linked-records-cell-more').getAttribute('aria-expanded')", 'true')
        ->keys('.fi-linked-records-cell-more', 'Escape')
        ->assertScript($shown, 0)
        ->assertScript("document.activeElement === document.querySelector('.fi-linked-records-cell-more')", true)
        ->click('.fi-linked-records-cell-more')
        ->assertScript($shown, 1)
        ->click('.fi-ta-header-cell-title')
        ->assertScript($shown, 0)
        ->assertScript("document.querySelector('.fi-modal .fi-linked-records') === null", true)
        ->assertNoJavaScriptErrors();
});
