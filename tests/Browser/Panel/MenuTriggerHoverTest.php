<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Models\Company;
use App\Models\SavedView;
use App\Models\Task;
use App\Models\User;

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();

    foreach (['Hot leads', 'Renewals'] as $name) {
        SavedView::factory()->for($this->user)->for($this->workspace)->create([
            'name' => $name,
            'state' => new TableStateData(sort: 'name:desc'),
        ]);
    }
});

it('keeps an item looking hovered while the menu opened from it is open', function (string $surface): void {
    $user = $this->user;
    $workspace = $this->workspace;
    $company = Company::query()->withoutGlobalScopes()->first();
    $company->tasks()->attach(Task::factory()->recycle([$user, $workspace])->create());

    $chip = "js:Livewire.all().find((component) => component.name.includes('ListCompanies')).\$wire.\$call('setFilterRules', [{ field: 'name', condition: 'contains', value: 'chip' }])";

    $dark = str_ends_with($surface, ' dark');
    $moreActions = __('filament/pages/dashboard.activation.more_actions');

    [$path, $prepare, $item, $trigger, $extras] = match (str_replace(' dark', '', $surface)) {
        'relation row' => ["companies/{$company->getKey()}", ['.fi-record-pane .fi-tabs-item:nth-child(4)'], '.fi-ta-row', '.fi-ta-row .fi-dropdown-trigger > button', []],
        'column header' => ['companies', [], 'th[x-data^="resizableColumn(\'created_at\'"]', 'th[x-data^="resizableColumn(\'created_at\'"] [data-column-menu-trigger]', ['th[x-data^="resizableColumn(\'created_at\'"] .column-resize-handle-bar::before']],
        'bulk bar more' => ['companies', ['.fi-ta-record-checkbox >> nth=0'], '.fi-bulk-bar .fi-dropdown-trigger > button', '.fi-bulk-bar .fi-dropdown-trigger > button', []],
        'import and export' => ['companies', [], '.fi-header button.fi-ac-btn-group:visible', '.fi-header button.fi-ac-btn-group:visible', []],
        'view switcher' => ['companies', [], '.fi-view-switcher .fi-dropdown-trigger button', '.fi-view-switcher .fi-dropdown-trigger button', []],
        'view settings' => ['companies', [], '.fi-view-settings-trigger', '.fi-view-settings-trigger', []],
        'filter add' => ['companies', [], '[data-filter-add]', '[data-filter-add]', []],
        'filter sort' => ['companies', [], '[data-filter-sort]', '[data-filter-sort]', []],
        'filter field segment' => ['companies', [$chip], '[data-filter-chip] [data-filter-segment="field"]', '[data-filter-chip] [data-filter-segment="field"]', []],
        'filter condition segment' => ['companies', [$chip], '[data-filter-chip] [data-filter-segment="condition"]', '[data-filter-chip] [data-filter-segment="condition"]', []],
        'filter value segment' => ['companies', [$chip], '[data-filter-chip] [data-filter-segment="value"]', '[data-filter-chip] [data-filter-segment="value"]', []],
        'filter group' => ['tasks', [], '[data-filter-group]', '[data-filter-group]', []],
        'notifications' => ['companies', [], '.fi-sidebar-notifications-btn', '.fi-sidebar-notifications-btn', []],
        'record actions' => ["companies/{$company->getKey()}", [], '.fi-record-page .fi-ac-btn-group:visible', '.fi-record-page .fi-ac-btn-group:visible', []],
        'workspace menu' => ['companies', [], '.fi-tenant-menu-trigger', '.fi-tenant-menu-trigger', ['.fi-tenant-menu-trigger .fi-icon']],
        'activation checklist' => ['companies', [], "button[aria-label=\"{$moreActions}\"]", "button[aria-label=\"{$moreActions}\"]", []],
        'filter more segment' => ['companies', [$chip], '[data-filter-chip] [data-filter-more]', '[data-filter-chip] [data-filter-more]', []],
    };

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}", 1440, 900);

    foreach ($prepare as $step) {
        if (str_starts_with($step, 'js:')) {
            $page->script(substr($step, 3));
            $page->wait(0.5);

            continue;
        }

        $page->click($step);
    }

    expectHoverHeldWhileOpen($page, $item, $trigger, $extras, $dark);

    $page->assertNoJavaScriptErrors();
})->with(['relation row', 'relation row dark', 'import and export dark', 'bulk bar more dark', 'notifications dark', 'workspace menu dark', 'filter add dark', 'activation checklist', 'column header', 'bulk bar more', 'import and export', 'view switcher', 'view settings', 'filter add', 'filter sort', 'filter field segment', 'filter condition segment', 'filter value segment', 'filter more segment', 'filter group', 'notifications', 'record actions', 'workspace menu']);
