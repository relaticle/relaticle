<?php

declare(strict_types=1);

use App\Filament\Concerns\CountsRelatedRecords;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(HasRecordPageLayout::class, CountsRelatedRecords::class);

it('sets the details rail beside the work pane on desktop and stacks it on a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Northwind Traders']);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertSeeIn('[data-page-heading]', 'Companies')
        ->assertSeeIn('[data-page-heading] h1', 'Northwind Traders')
        ->assertSeeIn('.fi-record-rail', 'Edit')
        ->assertNoJavaScriptErrors();

    $placement = <<<'JS'
        (() => {
            const rail = document.querySelector('.fi-record-rail').getBoundingClientRect();
            const pane = document.querySelector('.fi-record-pane').getBoundingClientRect();

            return {
                besideEachOther: Math.round(rail.right) <= Math.round(pane.left) && Math.round(rail.top) === Math.round(pane.top),
                stacked: rail.bottom <= pane.top,
                overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            };
        })();
    JS;

    expect($page->script($placement))->toMatchArray(['besideEachOther' => true, 'overflows' => false]);

    $page->resize(390, 844);

    expect($page->script($placement))->toMatchArray(['stacked' => true, 'overflows' => false]);
});

it('keeps the details rail collapsed after a reload', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create();
    $isCollapsed = "document.querySelector('#record-details section').classList.contains('fi-collapsed')";

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertScript($isCollapsed, false)
        ->click('#record-details .fi-section-collapse-btn')
        ->assertScript($isCollapsed, true);

    $page->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertScript($isCollapsed, true)
        ->assertNoJavaScriptErrors();
});

it('gives a long record name the full width of the header on a phone', function (int $width): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'MOBILE-AUDIT Long Name Industries International Holdings']);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize($width, 844)
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertScript("(() => { const name = document.querySelector('.fi-record-title .truncate'); const header = document.querySelector('.fi-record-title').closest('.fi-sc-flex'); return name.scrollHeight <= name.clientHeight && name.getBoundingClientRect().width >= header.getBoundingClientRect().width * 0.8; })()", true)
        ->assertNoJavaScriptErrors();
})->with([390, 320]);

it('reveals details past the first eight with view all', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    foreach (range(1, 8) as $position) {
        CustomField::factory()->create([
            'tenant_id' => $workspace->getKey(),
            'entity_type' => 'company',
            'type' => 'text',
            'code' => "extra_{$position}",
            'name' => "Extra detail {$position}",
            'sort_order' => 100 + $position,
        ]);
    }

    TenantContextService::setTenantId(null);

    $company = Company::factory()->recycle([$user, $workspace])->create();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->assertDontSee('Extra detail 8')
        ->click('View all')
        ->assertSee('Extra detail 8')
        ->click('Show less')
        ->assertDontSee('Extra detail 8')
        ->assertNoJavaScriptErrors();
});

it('raises the notes tab count when a note is added from the tab', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies/{$company->getKey()}")
        ->click('.fi-record-pane .fi-tabs-item:nth-child(5)')
        ->press('New note')
        ->type('[id="mountedActionSchema0.title"]', 'Kickoff notes')
        ->press('Create')
        ->assertSee('Kickoff notes');

    $page->assertScript(
        '(() => document.querySelector(".fi-record-pane .fi-tabs-item:nth-child(5)").innerText.replace(/\s+/g, " ").trim())()',
        'Notes 1',
    );
});

it('wraps a long rail label and gives the record buttons a 44px target on a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$workspace->slug}/opportunities/{$opportunity->getKey()}")
        ->assertVisible('.fi-record-rail')
        ->assertNoJavaScriptErrors();

    $measure = <<<'JS'
        (() => {
            const shown = (elements) => [...elements].filter((element) => element.checkVisibility());

            return {
                labelsClipped: shown(document.querySelectorAll('.fi-record-rail .fi-in-entry-label:not(.fi-sr-only)')).some((label) => label.scrollWidth > label.clientWidth),
                smallButtons: shown(document.querySelectorAll('.fi-record-page .fi-sc-actions :is(.fi-btn, .fi-icon-btn)')).filter((button) => button.getBoundingClientRect().height < 44).length,
            };
        })();
    JS;

    expect($page->script($measure))->toBe(['labelsClipped' => false, 'smallButtons' => 0]);
});

it('previews a company from its list row, steps to the next one and closes on escape', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    [$oldest, $newest] = collect([2, 1])
        ->map(fn (int $daysAgo): Company => Company::factory()->recycle([$user, $workspace])->create([
            'name' => $daysAgo === 2 ? 'Harbor Logistics' : 'Atlas Freight',
            'created_at' => now()->subDays($daysAgo),
        ]))
        ->all();

    $previewTitle = '(() => document.querySelector(".fi-modal-window.fi-record-preview .fi-record-preview-title")?.innerText.trim())()';

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/companies")
        ->hover('.fi-ta-row:first-of-type [class~="fi-ta-cell-name"]')
        ->click('.fi-ta-row:first-of-type .fi-record-preview-trigger')
        ->assertScript($previewTitle, 'Atlas Freight')
        ->assertSee('1 of 2 in Companies')
        ->assertScript('(() => { const header = document.querySelector(".fi-record-preview-header").getBoundingClientRect(); const open = document.querySelector(".fi-record-preview-open").getBoundingClientRect(); return header.right - open.right < 24; })()', true)
        ->assertScript('[...new Set([...document.querySelectorAll(".fi-record-preview-header .fi-icon-btn")].map((button) => { const box = button.getBoundingClientRect(); return `${box.width}x${box.height}`; }))].length', 1)
        ->click('.fi-record-preview-header button[aria-label="'.__('filament/record-page.preview.next').'"]')
        ->assertScript($previewTitle, 'Harbor Logistics')
        ->assertSee('2 of 2 in Companies')
        ->assertScript('document.querySelectorAll(".fi-modal-window.fi-record-preview").length', 1)
        ->keys('.fi-modal-window.fi-record-preview', ['Escape'])
        ->wait(0.5)
        ->assertDontSee('2 of 2 in Companies')
        ->assertScript('new URL(location.href).searchParams.has("relation")', false)
        ->assertNoJavaScriptErrors();
});

it('keeps the preview open while a tab lists the rest of a task\'s assignees', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Atlas Freight']);
    $member = User::factory()->create(['name' => 'Priya Raman']);
    $workspace->users()->attach($member, ['role' => 'member']);
    $task = Task::factory()->recycle([$user, $workspace])->create();
    $task->assignees()->attach([$user->getKey(), $member->getKey()]);
    $company->tasks()->attach($task);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/companies")
        ->hover('.fi-ta-row:first-of-type [class~="fi-ta-cell-name"]')
        ->click('.fi-ta-row:first-of-type .fi-record-preview-trigger')
        ->click('.fi-record-preview .fi-record-pane .fi-tabs-item:nth-child(4)')
        ->click('.fi-record-preview .fi-linked-records-cell-more')
        ->click('.fi-linked-records-cell-panel ul')
        ->wait(0.5)
        ->assertSee('Priya Raman')
        ->assertVisible('.fi-modal-window.fi-record-preview .fi-record-preview-header')
        ->assertNoJavaScriptErrors();
});
