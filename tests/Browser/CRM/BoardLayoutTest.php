<?php

declare(strict_types=1);

use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TasksBoard::class, OpportunitiesBoard::class);

it('scrolls a long column inside the board without scrolling the page', function (string $model, string $columnFieldCode, string $dateFieldCode, string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $columnField = CustomField::query()->forEntity($model)->where('code', $columnFieldCode)->firstOrFail();
    $dateField = CustomField::query()->forEntity($model)->where('code', $dateFieldCode)->firstOrFail();
    $firstColumn = $columnField->options->first();

    $records = $model::factory()->recycle([$user, $workspace])->count(20)->create();

    $records->each(function (Task|Opportunity $record) use ($columnField, $firstColumn, $dateField): void {
        $record->saveCustomFieldValue($columnField, $firstColumn->getKey());
        $record->saveCustomFieldValue($dateField, now()->addWeek());
    });

    TenantContextService::setTenantId(null);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->assertVisible("[data-card-id=\"{$records->first()->getKey()}\"]")
        ->assertNoJavaScriptErrors();

    $overflow = <<<'JS'
        (() => {
            const layout = document.querySelector('.fi-layout');
            const tray = document.querySelector('.ff-tray');
            const scroller = [tray, ...tray.querySelectorAll('*')]
                .find((element) => ['auto', 'scroll'].includes(getComputedStyle(element).overflowY));

            return {
                pageScrolls: layout.scrollHeight > layout.clientHeight,
                columnScrolls: scroller !== undefined && scroller.scrollHeight > scroller.clientHeight,
                gapBelowCards: Math.round(tray.getBoundingClientRect().bottom - scroller.getBoundingClientRect().bottom),
            };
        })();
    JS;

    expect($page->script($overflow))->toBe(['pageScrolls' => false, 'columnScrolls' => true, 'gapBelowCards' => 0]);
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, TaskField::DUE_DATE->value, 'tasks/board'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, OpportunityField::CLOSE_DATE->value, 'opportunities/board'],
]);

it('puts the badges of a task card below a short description', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $statusField = CustomField::query()->forEntity(Task::class)->where('code', TaskField::STATUS->value)->firstOrFail();
    $descriptionField = CustomField::query()->forEntity(Task::class)->where('code', TaskField::DESCRIPTION->value)->firstOrFail();

    $task = Task::factory()->recycle([$user, $workspace])->create(['title' => 'Short card']);
    $task->saveCustomFieldValue($statusField, $statusField->options->first()->getKey());
    $task->saveCustomFieldValue($descriptionField, '<p>Short</p>');
    $task->assignees()->attach($user);

    TenantContextService::setTenantId(null);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/tasks/board")
        ->assertVisible("[data-card-id=\"{$task->getKey()}\"] .ff-card-flex")
        ->assertNoJavaScriptErrors();

    $layout = $page->script(<<<JS
        (() => {
            const card = document.querySelector('[data-card-id="{$task->getKey()}"]');
            const description = [...card.querySelectorAll('.fi-in-text-item')].find((item) => item.textContent.trim() === 'Short');
            const badges = card.querySelector('.ff-card-flex');

            return {
                badgesBelowText: badges.getBoundingClientRect().top >= description.getBoundingClientRect().bottom,
                sameLeftEdge: Math.round(badges.getBoundingClientRect().left) === Math.round(description.getBoundingClientRect().left),
            };
        })();
    JS);

    expect($layout)->toBe(['badgesBelowText' => true, 'sameLeftEdge' => true]);
});

it('fills the viewport with a grouped board and lines its headers up with the flat board', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $stage = CustomField::query()->forEntity(Opportunity::class)->where('code', OpportunityField::STAGE->value)->firstOrFail();
    $firstStage = $stage->options->first();

    Opportunity::factory()->recycle([$user, $workspace])->count(12)->create()
        ->each(fn (Opportunity $opportunity) => $opportunity->saveCustomFieldValue($stage, $firstStage->getKey()));

    TenantContextService::setTenantId(null);

    $headerTop = 'Math.round(document.querySelector(".ff-column-header").getBoundingClientRect().top)';

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/opportunities/board")
        ->assertVisible('.ff-columns');

    $flatHeaderTop = $page->script($headerTop);

    $page->navigate("/app/{$workspace->slug}/opportunities/board?groupBy=company")
        ->assertVisible('.ff-lanes')
        ->assertNoJavaScriptErrors();

    $layout = <<<'JS'
        (() => {
            const layout = document.querySelector('.fi-layout');
            const lanes = document.querySelector('.ff-lanes');

            return {
                pageScrolls: layout.scrollHeight > layout.clientHeight,
                lanesScroll: lanes.scrollHeight > lanes.clientHeight,
                gapBelowLanes: Math.round(innerHeight - lanes.getBoundingClientRect().bottom),
            };
        })();
    JS;

    expect($page->script($layout))->toBe(['pageScrolls' => false, 'lanesScroll' => true, 'gapBelowLanes' => 0])
        ->and($page->script($headerTop))->toBe($flatHeaderTop);
});

it('joins the active filter trigger and its clear button into one pill', function (int $width, bool $iconOnly): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create();
    Opportunity::factory()->recycle([$user, $workspace])->for($company)->create();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize($width, 900)
        ->navigate("/app/{$workspace->slug}/opportunities/board?filters%5Bcompanies%5D%5Bvalues%5D%5B0%5D={$company->getKey()}")
        ->assertVisible('.fi-board-filter-clear')
        ->assertNoJavaScriptErrors();

    $pill = <<<'JS'
        (() => {
            const trigger = [...document.querySelectorAll('.fi-ta-filters-dropdown > .fi-dropdown-trigger :is(.fi-btn, .fi-icon-btn)')]
                .find((element) => element.checkVisibility());
            const triggerBox = trigger.getBoundingClientRect();
            const clearBox = document.querySelector('.fi-board-filter-clear').getBoundingClientRect();

            return {
                iconOnly: Math.round(triggerBox.width) === 32,
                height: Math.round(triggerBox.height),
                seam: Math.round(clearBox.left - triggerBox.right),
                sameRow: Math.round(clearBox.top) === Math.round(triggerBox.top),
                ringed: getComputedStyle(trigger).boxShadow !== 'none',
            };
        })();
    JS;

    expect($page->script($pill))->toBe(['iconOnly' => $iconOnly, 'height' => 32, 'seam' => 1, 'sameRow' => true, 'ringed' => true]);
})->with([
    'phone' => [390, true],
    'desktop' => [1440, false],
]);
