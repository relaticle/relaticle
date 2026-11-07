<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Filament\Tables\Concerns\HasTableLayouts;
use App\Models\Company;
use App\Models\TableLayout;
use App\Models\User;

mutates(HasTableLayouts::class);

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('applies a layout from the toolbar menu and marks a change as modified', function (): void {
    TableLayout::factory()->for($this->user)->for($this->workspace)->create([
        'name' => 'Hot leads',
        'state' => new TableStateData(sort: 'name:desc'),
    ]);

    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies")
        ->click('.fi-ta-layouts-trigger')
        ->click('Hot leads')
        ->assertSeeIn('.fi-ta-layouts-trigger', 'Hot leads')
        ->assertDontSee('(modified)');

    $page->type('.fi-ta-search-field input', 'zzz')
        ->assertSee('(modified)')
        ->click('.fi-ta-layouts [wire\:click="revertTableLayout"]')
        ->assertDontSee('(modified)')
        ->assertNoJavaScriptErrors();
});
