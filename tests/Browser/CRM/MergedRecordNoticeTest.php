<?php

declare(strict_types=1);

use App\Filament\Concerns\HasRecordPageLayout;
use App\Models\Company;
use App\Models\User;

mutates(HasRecordPageLayout::class);

it('sends a merged record to the record it became and shows a notice for a deleted one', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $result = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Northwind Holdings']);
    $merged = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Northwind Traders']);
    $merged->delete();
    Company::withoutGlobalScopes()->whereKey($merged->getKey())->update(['merged_into_id' => $result->getKey()]);
    $deleted = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Contoso Supply']);
    $deleted->delete();

    $page = visitAs($user, "/app/{$workspace->slug}/companies/{$merged->getKey()}", 1280, 800)
        ->assertSee('This record was merged')
        ->assertSee('It was merged with another record and no longer exists on its own.')
        ->assertSee('How merging works')
        ->assertDontSee('Northwind Traders')
        ->assertNoJavaScriptErrors()
        ->click('Go to record')
        ->assertPathIs("/app/{$workspace->slug}/companies/{$result->getKey()}")
        ->assertSeeIn('[data-page-heading] h1', 'Northwind Holdings');

    $page->navigate("/app/{$workspace->slug}/companies/{$deleted->getKey()}")
        ->assertSee('This record was deleted')
        ->assertDontSee('Contoso Supply')
        ->assertNoJavaScriptErrors()
        ->click('Go back')
        ->assertPathIs("/app/{$workspace->slug}/companies");
});
