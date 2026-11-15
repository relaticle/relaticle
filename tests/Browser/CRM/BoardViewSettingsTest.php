<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\SavedView;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TasksBoard::class, OpportunitiesBoard::class, MoveCardAction::class);
pest()->group('webkit');

it('filters a board from the bar, saves it as a view and finds it again from the list', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $stage = CustomField::query()->forEntity(Opportunity::class)->where('code', OpportunityField::STAGE->value)->firstOrFail();
    $acme = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Acme Robotics']);
    $globex = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Globex']);

    foreach ([['Acme rollout', $acme], ['Globex rollout', $globex]] as [$name, $company]) {
        Opportunity::factory()->recycle([$user, $workspace])->create(['name' => $name, 'company_id' => $company->getKey()])
            ->saveCustomFieldValue($stage, $stage->options->first()->getKey());
    }

    TenantContextService::setTenantId(null);

    $page = openBoard($user, 'opportunities/board');
    expect(boardCardCount($page, 2))->toBeTrue();

    $page->click('[data-filter-add]')
        ->click('[data-pop-item][data-field="company"]')
        ->click('[data-pop]:not([style*="display: none"]) [data-condition="is_any_of"]')
        ->click("[data-pop]:not([style*=\"display: none\"]) [data-option=\"{$acme->getKey()}\"]");
    expect(boardCardCount($page, 1))->toBeTrue();
    $page->assertSee('Acme rollout')->assertDontSee('Globex rollout')->assertSeeIn('[data-filter-chip] [data-filter-segment="value"]', 'Acme Robotics');

    $page->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('.fi-ta-views-save-current')
        ->type('.fi-modal-window input[type="text"]', 'Acme pipeline')
        ->click('.fi-modal-footer-actions button[type="submit"]')
        ->assertSeeIn('.fi-view-switcher-trigger', 'Acme pipeline');

    $page->navigate("/app/{$workspace->slug}/opportunities")
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('.fi-ta-views-row:has-text("Acme pipeline") .fi-dropdown-list-item')
        ->assertPathIs("/app/{$workspace->slug}/opportunities/board")
        ->assertVisible('.ff-column >> nth=0')
        ->assertSeeIn('[data-filter-chip] [data-filter-segment="value"]', 'Acme Robotics');
    expect(boardCardCount($page, 1))->toBeTrue();
    $page->assertSee('Acme rollout')->assertNoJavaScriptErrors();

    expect(SavedView::query()->withoutGlobalScopes()->where('name', 'Acme pipeline')->count())->toBe(1);
});

dataset('choice boards', [
    'tasks' => [Task::class, 'task', TaskField::STATUS->value, TaskField::PRIORITY->value, 'tasks/board', CrmEntity::Task],
    'opportunities' => [Opportunity::class, 'opportunity', OpportunityField::STAGE->value, 'region', 'opportunities/board', CrmEntity::Opportunity],
]);

function ensureOtherChoiceField(User $user, string $entity, string $code): void
{
    if ($code === 'region') {
        resolve(CreateCustomField::class)->execute($user, [
            'entity_type' => $entity,
            'name' => 'Region',
            'code' => 'region',
            'type' => 'select',
            'options' => [['name' => 'Americas'], ['name' => 'Europe'], ['name' => 'Asia'], ['name' => 'Africa']],
        ]);
    }
}

it('groups a board by another attribute, hides a column, drags a header and shows the column in its old slot', function (string $model, string $entity, string $stageCode, string $otherCode, string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    ensureOtherChoiceField($user, $entity, $otherCode);

    $stageIds = boardStageOptionIds($model, $stageCode);
    $ids = boardStageOptionIds($model, $otherCode);
    $hidden = $ids[1];
    $afterHide = array_values(array_diff($ids, [$hidden]));
    $afterDrag = [$afterHide[1], $afterHide[0], ...array_slice($afterHide, 2)];
    $afterShow = [$afterHide[1], $hidden, $afterHide[0], ...array_slice($afterHide, 2)];

    $page = openBoard($user, $path);
    waitForBoardColumns($page, $stageIds);

    openViewSettingsPanel($page, '[data-board-column-field-trigger]');
    $page->click("[data-board-column-field=\"{$otherCode}\"]");
    waitForBoardColumns($page, $ids);
    $page->assertScript('document.querySelector("[data-board-column-field=\"'.$otherCode.'\"]").getAttribute("aria-checked")', 'true');

    $page->click('[data-board-panel="grouping"] [data-view-settings-back]')->click('[data-board-columns-trigger]');
    $page->click("[data-board-view-settings] [data-stage-id=\"{$hidden}\"] [data-board-column-toggle]");
    waitForBoardColumns($page, $afterHide);

    closeViewSettings($page);

    dragWithPointerEvents($page, boardHeaderTitle($afterHide[1]), boardHeaderTitle($afterHide[0]));
    waitForBoardColumns($page, $afterDrag);

    $page->click('.fi-view-settings-trigger')->click('[data-board-columns-trigger]')
        ->click("[data-board-view-settings] [data-stage-id=\"{$hidden}\"] [data-board-column-toggle]");
    waitForBoardColumns($page, $afterShow);

    $page->navigate("/app/{$user->ownedWorkspaces()->first()->slug}/{$path}")->assertVisible('.ff-column >> nth=0');
    waitForBoardColumns($page, $afterShow);
    $page->assertNoJavaScriptErrors();
})->with('choice boards');

function settingsPanel(): string
{
    return 'window.Alpine.$data(document.querySelector("[data-board-view-settings]")).panel';
}

it('keeps a view settings panel open on the same panel after a switch, a move up and a dragged row', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $last = last($ids);

    $page = openBoard($user, $path)->click('.fi-view-settings-trigger')->click('[data-board-columns-trigger]');
    $page->assertScript(settingsPanel(), 'columns');

    $page->click("[data-board-view-settings] [data-stage-id=\"{$last}\"] [data-board-column-toggle]");
    waitForBoardColumns($page, array_slice($ids, 0, -1));
    $page->assertScript(settingsPanel(), 'columns');

    pickRowMenuItem($page, "[data-board-view-settings] [data-stage-id=\"{$ids[1]}\"] [data-stage-actions]", 'Move up');
    waitForStoredStageOrder($page, $user, $entity, [$ids[1], $ids[0], ...array_slice($ids, 2)]);
    $page->wait(0.5)->assertScript(settingsPanel(), 'columns')->assertScript(BOARD_STAGE_ROW_IDS, [$ids[1], $ids[0], ...array_slice($ids, 2)]);

    $page->assertScript('(async () => { const bound = () => Object.keys(document.querySelector("[data-board-view-settings] .fi-ta-views-list")).some((key) => key.startsWith("Sortable")); for (let attempt = 0; ! bound() && attempt < 50; attempt++) { await new Promise((resolve) => setTimeout(resolve, 100)); } return bound(); })()', true);
    dragSettingsRow($page, "[data-board-view-settings] [data-stage-id=\"{$ids[0]}\"] .fi-board-stage", "[data-board-view-settings] [data-stage-id=\"{$ids[1]}\"] .fi-board-stage");
    waitForStoredStageOrder($page, $user, $entity, $ids);
    $page->wait(0.5)->assertScript(settingsPanel(), 'columns')->assertScript(BOARD_STAGE_ROW_IDS, $ids);

    $page->click('[data-board-panel="columns"] [data-view-settings-back]')->assertScript(settingsPanel(), 'main');
    $before = $page->script(BOARD_ATTRIBUTE_KEYS);
    expect(count($before))->toBeGreaterThan(2);

    $page->click('[data-board-view-settings] [data-board-hide-empty]')->wait(1)->assertScript(settingsPanel(), 'main');

    pickRowMenuItem($page, '[data-board-view-settings] [data-card-attribute]:nth-child(2) [data-attribute-actions]', 'Move up');
    $swapped = [$before[1], $before[0], ...array_slice($before, 2)];
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->cardAttributes === $swapped);
    expect(storedBoardState($user, $entity)->cardAttributes)->toBe($swapped);
    $page->wait(0.5)->assertScript(settingsPanel(), 'main')->assertScript(BOARD_ATTRIBUTE_KEYS, $swapped);

    dragSettingsRow($page, '[data-board-view-settings] [data-card-attribute]:nth-child(1) .fi-board-stage', '[data-board-view-settings] [data-card-attribute]:nth-child(2) .fi-board-stage');
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->cardAttributes === $before);
    expect(storedBoardState($user, $entity)->cardAttributes)->toBe($before);
    $page->wait(0.5)->assertScript(settingsPanel(), 'main')->assertNoJavaScriptErrors();
})->with('stage order boards');

function boardSize(string $column, string $card): string
{
    return "(async () => { await new Promise((resolve) => setTimeout(resolve, 300)); return { column: Math.round(document.querySelector('{$column}').getBoundingClientRect().width), card: Math.round(document.querySelector('{$card}').getBoundingClientRect().height) }; })()";
}

it('compacts cards flat and with lanes, every time it is pressed, and keeps phones at their own width', function (string $model, string $fieldCode, string $path, CrmEntity $entity, string $groupBy): void {
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

    $flat = boardSize('.ff-columns > .ff-column', '.ff-column .ff-card');
    $lanes = boardSize('.ff-lanes-heads > .ff-column', '.ff-cell .ff-card');

    $page = openBoard($user, $path, 390);
    $phone = $page->script($flat);

    $page->resize(1440, 900)->assertVisible('.ff-card');
    $full = $page->script($flat);

    pressLayout($page, 'card-size', 'compact', 'boardCardSize');
    $compact = $page->script($flat);
    expect($compact['card'])->toBeLessThan($full['card'])->and($compact['column'])->toBe($full['column']);

    pressLayout($page, 'card-size', 'full', 'boardCardSize');
    expect($page->script($flat))->toBe($full);

    pressLayout($page, 'card-size', 'compact', 'boardCardSize');
    expect($page->script($flat))->toBe($compact);

    openViewSettingsPanel($page, '[data-board-lanes-trigger]');
    $page->click("[data-board-grouping=\"{$groupBy}\"]")->assertVisible('.ff-lanes-heads');
    closeViewSettings($page);

    $laneCompact = $page->script($lanes);
    expect($laneCompact['column'])->toBe($full['column']);

    pressLayout($page, 'card-size', 'full', 'boardCardSize');
    expect($page->script($lanes)['card'])->toBeGreaterThan($laneCompact['card']);

    $page->resize(390, 844)->navigate("/app/{$workspace->slug}/{$path}")->assertVisible('.ff-column >> nth=0');
    $page->assertScript(boardLayoutIs('boardCardSize', 'full'), true);
    expect($page->script('Math.round(document.querySelector(".ff-lanes-heads > .ff-column, .ff-columns > .ff-column").getBoundingClientRect().width)'))->toBe($phone['column']);
    expect($page->script('Math.round(document.querySelectorAll(".ff-lanes-heads > .ff-column, .ff-columns > .ff-column")[1].getBoundingClientRect().left)'))->toBeLessThan(390);
    $page->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task, 'assignee'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity, 'company'],
]);

it('keeps the add row in place when the cards switch between compact and full', function (string $model, string $fieldCode, string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $addRow = 'JSON.stringify((() => { const row = document.querySelector(".ff-columns > .ff-column .ff-add-slot .ff-add-row"); const box = row.getBoundingClientRect(); const icon = row.querySelector(".ff-add-row-icon").getBoundingClientRect(); return [box.left, box.top, box.width, box.height, icon.left]; })())';

    $page = openBoard($user, $path);
    $full = $page->script($addRow);

    pressLayout($page, 'card-size', 'compact', 'boardCardSize');

    expect($page->script($addRow))->toBe($full);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');
