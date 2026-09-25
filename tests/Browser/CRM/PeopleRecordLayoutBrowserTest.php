<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\People;
use App\Models\User;

mutates(ViewPeople::class);

it('places person details in a left rail beside tasks', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create([
        'name' => 'Mail',
    ]);
    $person = People::factory()->recycle([$user, $workspace])->create([
        'name' => 'Ilya Pashayan',
        'company_id' => $company->getKey(),
    ]);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/people/{$person->getKey()}")
        ->assertSee('Ilya Pashayan')
        ->assertSee('Mail')
        ->assertVisible('.fi-record-details-rail')
        ->assertVisible('.fi-record-work-pane')
        ->assertSee(__('filament/resources/task.navigation_label'))
        ->assertNoJavaScriptErrors();

    $layout = $page->script(<<<'JS'
        (() => {
            const rail = document.querySelector('.fi-record-details-rail').getBoundingClientRect();
            const pane = document.querySelector('.fi-record-work-pane').getBoundingClientRect();
            const tabs = document.querySelector('.fi-record-work-pane .fi-tabs:not(.fi-contained)').getBoundingClientRect();
            const kebab = document.querySelector('.fi-record-details-more').getBoundingClientRect();

            const name = document.querySelector('[data-inline-field="name"]');
            const nameLabel = name?.querySelector('.fi-in-entry-label:not(.fi-sr-only)');
            const nameValue = name?.querySelector('.fi-in-entry-content')?.getBoundingClientRect();
            const company = document.querySelector('[data-inline-field="company_id"]');
            const companyLabel = company?.querySelector('.fi-in-entry-label')?.getBoundingClientRect();
            const companyValue = company?.querySelector('.fi-in-entry-content')?.getBoundingClientRect();

            return {
                paneRightOfRail: pane.left >= rail.right - 1,
                railNarrow: rail.width <= 420,
                paneWiderThanRail: pane.width > rail.width,
                kebabInRail: kebab.left >= rail.left - 1 && kebab.right <= rail.right + 1,
                kebabAtRailEnd: Math.abs(kebab.right - rail.right) <= 16,
                kebabBesideName: !!nameValue && Math.abs((kebab.top + kebab.bottom) / 2 - (nameValue.top + nameValue.bottom) / 2) <= 16,
                railAlignedWithTabs: Math.abs(rail.top - tabs.top) <= 8,
                nameHasNoVisibleLabel: !nameLabel || nameLabel.classList.contains('fi-sr-only') || getComputedStyle(nameLabel).position === 'absolute',
                nameChipVisible: !!nameValue && nameValue.width > 0,
                companyLabelLeftOfValue: !!companyLabel && !!companyValue && companyLabel.right <= companyValue.left + 4,
            };
        })();
    JS);

    expect($layout)->toMatchArray([
        'paneRightOfRail' => true,
        'railNarrow' => true,
        'paneWiderThanRail' => true,
        'kebabInRail' => true,
        'kebabAtRailEnd' => true,
        'kebabBesideName' => true,
        'railAlignedWithTabs' => true,
        'nameHasNoVisibleLabel' => true,
        'nameChipVisible' => true,
        'companyLabelLeftOfValue' => true,
    ]);

    $page->click(__('activity-log::messages.title'))
        ->waitForText(__('activity-log::messages.groups.this_week'), 10);

    $activityLayout = $page->script(<<<'JS'
        (() => {
            const rail = document.querySelector('.fi-record-details-rail').getBoundingClientRect();
            const tabs = document.querySelector('.fi-record-work-pane .fi-tabs:not(.fi-contained)').getBoundingClientRect();

            return {
                railAlignedWithTabs: Math.abs(rail.top - tabs.top) <= 8,
            };
        })();
    JS);

    expect($activityLayout)->toMatchArray([
        'railAlignedWithTabs' => true,
    ]);
});
