<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TasksBoard::class, OpportunitiesBoard::class, MoveCardAction::class);
pest()->group('webkit');

it('collapses every column from the bar and expands them again, flat and with lanes, and keeps it after a reload', function (string $model, string $fieldCode, string $path, CrmEntity $entity, string $groupBy): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    $company = Company::factory()->recycle([$user, $workspace])->create();
    $record = $model::factory()->recycle([$user, $workspace])->create($model === Opportunity::class ? ['company_id' => $company->getKey()] : []);
    $record->saveCustomFieldValue($field, $field->options->first()->getKey());

    if ($record instanceof Task) {
        $record->assignees()->attach($user);
    }

    TenantContextService::setTenantId(null);

    $columns = $field->options->count();
    $page = openBoard($user, $path);

    $page->assertScript('document.querySelectorAll(".ff-column[data-collapsed]").length', 0);

    pressLayout($page, 'columns', 'collapsed', 'boardColumns');
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-column[data-collapsed]').length === {$columns}"), true);
    expect(storedBoardState($user, $entity)?->columnsCollapsed)->toBeTrue();

    $page->navigate("/app/{$workspace->slug}/{$path}")->assertVisible('.ff-column >> nth=0');
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-column[data-collapsed]').length === {$columns}"), true);

    pressLayout($page, 'columns', 'expanded', 'boardColumns');
    $page->assertScript(boardWaitFor('document.querySelectorAll(".ff-column[data-collapsed]").length === 0'), true);
    expect(storedBoardState($user, $entity)?->columnsCollapsed)->toBeFalse();

    openViewSettingsPanel($page, '[data-board-lanes-trigger]');
    $page->click("[data-board-grouping=\"{$groupBy}\"]")->assertVisible('.ff-lanes-heads');
    closeViewSettings($page);

    pressLayout($page, 'columns', 'collapsed', 'boardColumns');
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-lanes-heads > .ff-column[data-collapsed]').length === {$columns}"), true);

    pressLayout($page, 'columns', 'expanded', 'boardColumns');
    $page->assertScript(boardWaitFor('document.querySelectorAll(".ff-lanes-heads > .ff-column[data-collapsed]").length === 0'), true)
        ->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task, 'assignee'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity, 'company'],
]);

it('collapses a stage from its header menu and expands it by clicking its rail, flat and with lanes', function (string $model, string $fieldCode, string $path, CrmEntity $entity, string $groupBy): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    $first = (string) $field->options->first()->getKey();
    $company = Company::factory()->recycle([$user, $workspace])->create();
    $record = $model::factory()->recycle([$user, $workspace])->create($model === Opportunity::class ? ['company_id' => $company->getKey()] : []);
    $record->saveCustomFieldValue($field, $first);

    if ($record instanceof Task) {
        $record->assignees()->attach($user);
    }

    TenantContextService::setTenantId(null);

    $page = openBoard($user, $path);

    collapseStageFromMenu($page, $first);
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === [$first]);
    expect(storedBoardState($user, $entity)?->collapsedColumns)->toBe([$first]);
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-column[data-collapsed]').length === 1"), true);

    $page->page()->locator('.ff-column[data-collapsed]')->click();
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === []);
    expect(storedBoardState($user, $entity)?->collapsedColumns)->toBe([]);
    $page->assertScript(boardWaitFor('document.querySelectorAll(".ff-column[data-collapsed]").length === 0'), true);

    openViewSettingsPanel($page, '[data-board-lanes-trigger]');
    $page->click("[data-board-grouping=\"{$groupBy}\"]")->assertVisible('.ff-lanes-heads');
    closeViewSettings($page);

    collapseStageFromMenu($page, $first, '.ff-lanes-heads');
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === [$first]);
    expect(storedBoardState($user, $entity)?->collapsedColumns)->toBe([$first]);
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-lanes-heads > .ff-column[data-collapsed]').length === 1"), true);

    $page->page()->locator('.ff-lanes-heads > .ff-column[data-collapsed]')->click();
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === []);
    expect(storedBoardState($user, $entity)?->collapsedColumns)->toBe([]);
    $page->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task, 'assignee'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity, 'company'],
]);

it('collapses a stage on a double-click in its empty space, flat and with lanes', function (string $model, string $fieldCode, string $path, CrmEntity $entity, string $groupBy): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    [$first, $second] = $field->options->take(2)->map(fn (CustomFieldOption $option): string => (string) $option->getKey())->all();
    $company = Company::factory()->recycle([$user, $workspace])->create();
    $record = $model::factory()->recycle([$user, $workspace])->create($model === Opportunity::class ? ['company_id' => $company->getKey()] : []);
    $record->saveCustomFieldValue($field, $first);

    if ($record instanceof Task) {
        $record->assignees()->attach($user);
    }

    TenantContextService::setTenantId(null);

    $page = openBoard($user, $path);

    $page->page()->locator("[data-column-root=\"{$second}\"] .ff-cards")->dblclick();
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === [$second]);
    expect(storedBoardState($user, $entity)?->collapsedColumns)->toBe([$second]);

    $page->page()->locator("[data-column-root=\"{$second}\"][data-collapsed]")->click();
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === []);

    openViewSettingsPanel($page, '[data-board-lanes-trigger]');
    $page->click("[data-board-grouping=\"{$groupBy}\"]")->assertVisible('.ff-lanes-heads');
    closeViewSettings($page);

    $page->page()->locator(".ff-cell-cards[data-column-id=\"{$second}\"]")->first()->dblclick();
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === [$second]);
    expect(storedBoardState($user, $entity)?->collapsedColumns)->toBe([$second]);
    $page->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task, 'assignee'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity, 'company'],
]);

it('opens one column out of collapse all on a double-click and switches the bar to expanded', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);

    $page = openBoard($user, $path);
    pressLayout($page, 'columns', 'collapsed', 'boardColumns');
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-column[data-collapsed]').length === ".count($ids)), true);

    $page->page()->locator("[data-column-root=\"{$ids[1]}\"][data-collapsed]")->dblclick();
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->columnsCollapsed === false);

    $page->assertScript(boardWaitFor("document.querySelector('[data-column-root=\"{$ids[1]}\"]').hasAttribute('data-collapsed') === false"), true)
        ->assertScript('document.querySelectorAll(".ff-column[data-collapsed]").length', count($ids) - 1)
        ->assertScript('document.querySelector(\'[data-board-columns-option="expanded"]\').getAttribute("aria-pressed")', 'true');

    expect(storedBoardState($user, $entity)->collapsedColumns)->toEqualCanonicalizing(array_values(array_diff($ids, [$ids[1]])));
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('opens only the clicked column out of collapse all on a single click', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);

    $page = openBoard($user, $path);
    pressLayout($page, 'columns', 'collapsed', 'boardColumns');
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-column[data-collapsed]').length === ".count($ids)), true);

    $page->page()->locator("[data-column-root=\"{$ids[1]}\"][data-collapsed]")->click();
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->columnsCollapsed === false);

    $page->assertScript(boardWaitFor("document.querySelector('[data-column-root=\"{$ids[1]}\"]').hasAttribute('data-collapsed') === false"), true)
        ->assertScript('document.querySelectorAll(".ff-column[data-collapsed]").length', count($ids) - 1)
        ->assertScript('document.querySelector(\'[data-board-columns-option="expanded"]\').getAttribute("aria-pressed")', 'true');

    expect(storedBoardState($user, $entity)->collapsedColumns)->toEqualCanonicalizing(array_values(array_diff($ids, [$ids[1]])));
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('lets a phone expand the columns that collapse all folded', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());
    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    $columns = $field->options->count();
    TenantContextService::setTenantId(null);

    $page = openBoard($user, $path);
    pressLayout($page, 'columns', 'collapsed', 'boardColumns');
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-column[data-collapsed]').length === {$columns}"), true);

    $page->resize(390, 844)->navigate("/app/{$workspace->slug}/{$path}")->assertVisible('.ff-column >> nth=0');
    $page->assertVisible('[data-board-columns-option="expanded"]')
        ->assertScript(boardWaitFor("document.querySelectorAll('.ff-column[data-collapsed]').length === {$columns}"), true);

    $page->click('[data-board-columns-option="expanded"]');
    $page->assertScript(boardWaitFor('document.querySelectorAll(".ff-column[data-collapsed]").length === 0'), true);
    expect(storedBoardState($user, $entity)?->columnsCollapsed)->toBeFalse();
    $page->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity],
]);

it('collapses one stage after collapse all and expand all on one page load, and its rail expands only itself', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());
    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    $first = (string) $field->options->first()->getKey();
    $columns = $field->options->count();
    TenantContextService::setTenantId(null);

    $page = openBoard($user, $path);

    pressLayout($page, 'columns', 'collapsed', 'boardColumns');
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-column[data-collapsed]').length === {$columns}"), true);
    pressLayout($page, 'columns', 'expanded', 'boardColumns');
    $page->assertScript(boardWaitFor('document.querySelectorAll(".ff-column[data-collapsed]").length === 0'), true);

    collapseStageFromMenu($page, $first);
    $page->assertScript(boardWaitFor("document.querySelectorAll('.ff-column[data-collapsed]').length === 1"), true);
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === [$first]);

    $page->page()->locator("[data-column-root=\"{$first}\"][data-collapsed]")->click();
    $page->assertScript(boardWaitFor('document.querySelectorAll(".ff-column[data-collapsed]").length === 0'), true);
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === []);

    expect(storedBoardState($user, $entity)->collapsedColumns)->toBe([])
        ->and(storedBoardState($user, $entity)->columnsCollapsed)->toBeFalse();
    $page->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity],
]);

it('drags an open column by its empty tray and keeps the order after a reload', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $ids = boardStageOptionIds($model, $fieldCode);
    $expected = [$ids[1], $ids[0], ...array_slice($ids, 2)];

    $page = openBoard($user, $path);

    dragWithPointerEvents($page, "[data-column-root=\"{$ids[0]}\"] .ff-tray", boardHeaderTitle($ids[1]));
    waitForStoredStageOrder($page, $user, $entity, $expected);
    expect(storedStageOrder($user, $entity))->toBe($expected);

    $page->navigate("/app/{$workspace->slug}/{$path}")->assertVisible('.ff-column >> nth=0');
    waitForBoardColumns($page, $expected);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('drags a collapsed rail, keeps the order and leaves it collapsed, while a plain click still expands it', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $ids = boardStageOptionIds($model, $fieldCode);
    $expected = [$ids[1], $ids[0], ...array_slice($ids, 2)];
    $rail = fn (string $id): string => "[data-column-root=\"{$id}\"][data-collapsed]";

    $page = openBoard($user, $path);

    foreach ([$ids[0], $ids[1]] as $id) {
        collapseStageFromMenu($page, $id);
        $page->assertScript(boardWaitFor("document.querySelector('{$rail($id)}') !== null"), true);
    }

    dragCard($page, $rail($ids[0]), $rail($ids[1]));
    waitForStoredStageOrder($page, $user, $entity, $expected);
    $page->wait(0.6);

    expect(storedStageOrder($user, $entity))->toBe($expected)
        ->and(storedBoardState($user, $entity)->collapsedColumns)->toEqualCanonicalizing([$ids[0], $ids[1]]);
    $page->assertScript('document.querySelectorAll(".ff-column[data-collapsed]").length', 2);

    $page->navigate("/app/{$workspace->slug}/{$path}")->assertVisible('.ff-column >> nth=0');
    waitForBoardColumns($page, $expected);
    $page->assertScript('document.querySelectorAll(".ff-column[data-collapsed]").length', 2);

    $page->page()->locator($rail($ids[0]))->click();
    $page->assertScript(boardWaitFor("document.querySelector('{$rail($ids[0])}') === null"), true);
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->collapsedColumns === [$ids[1]]);
    expect(storedBoardState($user, $entity)->collapsedColumns)->toBe([$ids[1]]);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('still moves a card between two columns now that a column drags by its tray', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());
    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    [$from, $to] = [$field->options->get(0), $field->options->get(1)];
    $record = $model::factory()->recycle([$user, $workspace])->create();
    $record->saveCustomFieldValue($field, $from->getKey());
    TenantContextService::setTenantId(null);

    $card = "[data-card-id=\"{$record->getKey()}\"]";
    $page = openBoard($user, $path)->assertVisible($card);

    dragCard($page, $card, "[data-column-root=\"{$to->getKey()}\"] .ff-cards");
    $page->assertScript(boardWaitFor("document.querySelector('[data-column-root=\"{$to->getKey()}\"] {$card}') !== null"), true);
    $page->wait(1);

    TenantContextService::setTenantId($workspace->getKey());
    $stored = $record->refresh()->customFieldValues()->where('custom_field_id', $field->getKey())->value($field->getValueColumn());
    TenantContextService::setTenantId(null);

    expect((string) $stored)->toBe((string) $to->getKey())
        ->and(storedStageOrder($user, $entity))->toBeNull();
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');
