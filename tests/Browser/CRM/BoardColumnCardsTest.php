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
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TasksBoard::class, OpportunitiesBoard::class, MoveCardAction::class);
pest()->group('webkit');

function boardColumnValue(Task|Opportunity $record, CustomField $field): mixed
{
    return $record->fresh()->customFieldValues()->where('custom_field_id', $field->getKey())->value($field->getValueColumn());
}

function waitForColumnValue(mixed $page, Task|Opportunity $record, CustomField $field, mixed $expected): void
{
    waitForPage($page, fn (): bool => boardColumnValue($record, $field) === $expected);
    expect(boardColumnValue($record, $field))->toBe($expected);
}

it('shows the no value column from the first row, ringed in grey, takes cards in and out and cannot be dragged', function (string $model, string $fieldCode, string $path, CrmEntity $entity, string $groupBy): void {
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

    $ids = boardStageOptionIds($model, $fieldCode);
    $none = '__none__';
    $card = "[data-card-id=\"{$record->getKey()}\"]";

    $page = openBoard($user, $path)->assertVisible($card);
    waitForBoardColumns($page, $ids);

    openViewSettingsPanel($page, '[data-board-columns-trigger]');
    $page->assertScript('document.querySelector("[data-board-no-value-toggle]") === document.querySelector("[data-board-panel=columns] .fi-ta-views-row")', true)
        ->click('[data-board-no-value-toggle] [data-board-column-toggle]');
    waitForBoardColumns($page, [$none, ...$ids]);
    closeViewSettings($page);

    $ring = $page->script("(() => { const ring = getComputedStyle(document.querySelector('[data-column-root=\"{$none}\"] .ff-column-heading'), '::before'); return { width: Math.round(parseFloat(ring.width)), ringed: parseFloat(ring.borderTopWidth) >= 1 && parseFloat(ring.borderTopWidth) <= 1.5, style: ring.borderTopStyle, fill: ring.backgroundColor, grey: ring.borderTopColor !== getComputedStyle(document.querySelector('.ff-column-title')).color }; })()");
    expect($ring)->toBe(['width' => 8, 'ringed' => true, 'style' => 'solid', 'fill' => 'rgba(0, 0, 0, 0)', 'grey' => true]);

    dragWithPointerEvents($page, boardHeaderTitle($none), boardHeaderTitle($ids[1]));
    $page->wait(1);
    waitForBoardColumns($page, [$none, ...$ids]);
    expect(storedBoardState($user, $entity)?->stageOrder ?? [])->toBe([]);

    dragCard($page, $card, "[data-column-root=\"{$none}\"] .ff-cards");
    waitForColumnValue($page, $record, $field, null);
    $page->assertScript("document.querySelector('{$card}').closest('[data-column-root]').dataset.columnRoot", $none);

    dragCard($page, $card, "[data-column-root=\"{$ids[1]}\"] .ff-cards");
    waitForColumnValue($page, $record, $field, $ids[1]);

    openViewSettingsPanel($page, '[data-board-lanes-trigger]');
    $page->click("[data-board-grouping=\"{$groupBy}\"]")->assertVisible('.ff-lanes-heads');
    closeViewSettings($page);

    $lane = $page->script("document.querySelector('{$card}').closest('[data-lane-id]').dataset.laneId");
    $cell = fn (string $columnId): string => ".ff-cell:has([data-column-id=\"{$columnId}\"][data-lane-id=\"{$lane}\"])";

    dragCard($page, $card, $cell($none));
    waitForColumnValue($page, $record, $field, null);
    $page->assertScript("document.querySelector('{$card}').closest('[data-column-id]').dataset.columnId", $none);

    dragCard($page, $card, $cell($ids[0]));
    waitForColumnValue($page, $record, $field, $ids[0]);

    dragWithPointerEvents($page, boardHeaderTitle($none, '.ff-lanes-heads'), boardHeaderTitle($ids[1], '.ff-lanes-heads'));
    $page->wait(1)->assertScript('[...document.querySelectorAll(".ff-lanes-heads > .ff-column")].map((column) => column.dataset.columnRoot)', [$none, ...$ids])
        ->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task, 'assignee'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity, 'company'],
]);

it('creates a card from the header at the bottom of the first visible column', function (string $model, string $fieldCode, string $path, string $titleColumn): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    $ids = boardStageOptionIds($model, $fieldCode);

    foreach (['Existing one' => 1000, 'Existing two' => 2000] as $title => $position) {
        $model::factory()->recycle([$user, $workspace])->create([$titleColumn => $title, 'order_column' => $position])
            ->saveCustomFieldValue($field, $field->options->get(1)->getKey());
    }

    TenantContextService::setTenantId(null);

    $page = openBoard($user, $path);
    expect(boardCardCount($page, 2))->toBeTrue();

    openViewSettingsPanel($page, '[data-board-columns-trigger]');
    $page->click("[data-board-view-settings] [data-stage-id=\"{$ids[0]}\"] [data-board-column-toggle]");
    waitForBoardColumns($page, array_slice($ids, 1));
    closeViewSettings($page);

    $page->click('.fi-board-header-actions button:has-text("New")')
        ->assertVisible('.fi-modal-window')
        ->type('.fi-modal-window input[type="text"] >> nth=0', 'Brand new card')
        ->click('.fi-modal-footer-actions button[type="submit"]');

    expect(boardCardCount($page, 3))->toBeTrue();
    $page->assertScript("[...document.querySelectorAll('[data-column-root=\"{$ids[1]}\"] .ff-card')].map((card) => card.textContent.includes('Brand new card'))", [false, false, true])
        ->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', 'title'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', 'name'],
]);

function cardTextBefore(string $card, string $first, string $second): string
{
    return "(() => { const walker = document.createTreeWalker(document.querySelector('{$card} .ff-card-body'), NodeFilter.SHOW_TEXT); const nodes = []; while (walker.nextNode()) { nodes.push(walker.currentNode); } const [a, b] = ['{$first}', '{$second}'].map((text) => nodes.find((node) => node.textContent.includes(text))); return !! a && !! b && !! (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING); })()";
}

it('adds an attribute to the card, reorders two with a drag of the handle and removes one', function (string $model, string $fieldCode, string $path, CrmEntity $entity, string $markerKey): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create(['name' => 'Olivia Hart']);
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();

    if ($model === Opportunity::class) {
        $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Northwind Traders']);
        $record = Opportunity::factory()->recycle([$user, $workspace])->create(['company_id' => $company->getKey(), 'creator_id' => $user->getKey()]);
        $marker = 'Northwind Traders';
    } else {
        $priority = CustomField::query()->forEntity($model)->where('code', TaskField::PRIORITY->value)->firstOrFail();
        $record = Task::factory()->recycle([$user, $workspace])->create(['creator_id' => $user->getKey()]);
        $record->saveCustomFieldValue($priority, $priority->options->first()->getKey());
        $marker = $priority->options->first()->name;
    }

    $record->saveCustomFieldValue($field, $field->options->first()->getKey());

    TenantContextService::setTenantId(null);

    $card = "[data-card-id=\"{$record->getKey()}\"]";
    $creator = 'Olivia Hart';

    $page = openBoard($user, $path)->assertVisible($card);
    $page->assertSeeIn($card, $marker)->assertDontSeeIn($card, $creator);

    openViewSettingsPanel($page, '[data-board-add-attribute]');
    $page->click('[data-board-add-item="creator"]')->assertScript(boardWaitFor("document.querySelector('{$card}').textContent.includes('{$creator}')"), true);
    $page->assertScript(cardTextBefore($card, $marker, $creator), true);

    $page->click('[data-board-panel="add"] [data-view-settings-back]');
    $keys = $page->script(BOARD_ATTRIBUTE_KEYS);
    expect(last($keys))->toBe('creator');

    $order = $keys;

    for ($at = count($order) - 1; $at > array_search($markerKey, $keys, true); $at--) {
        dragSettingsRow($page, '[data-board-view-settings] [data-card-attribute="creator"] .fi-board-stage-grip', "[data-board-view-settings] [data-card-attribute=\"{$order[$at - 1]}\"] .fi-board-stage");
        [$order[$at - 1], $order[$at]] = [$order[$at], $order[$at - 1]];
        waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->cardAttributes === $order);
        expect(storedBoardState($user, $entity)->cardAttributes)->toBe($order);
    }

    $page->assertScript(boardWaitFor(cardTextBefore($card, $creator, $marker)), true);

    pickRowMenuItem($page, "[data-board-view-settings] [data-card-attribute=\"{$markerKey}\"] [data-attribute-actions]", __('filament/pages/boards.view_settings.remove_attribute'));
    $page->assertScript(boardWaitFor("! document.querySelector('{$card}').textContent.includes('{$marker}')"), true)
        ->assertScript('document.querySelectorAll("[data-board-view-settings] [data-card-attribute=\"'.$markerKey.'\"]").length', 0)
        ->assertSeeIn($card, $creator);
    expect(storedBoardState($user, $entity)->cardAttributes)->not->toContain($markerKey);

    $page->click('[data-board-add-attribute]')->click('[data-view-settings-create]')->assertVisible('.fi-modal-window');
    $page->assertScript(boardWaitFor('document.activeElement?.closest(".fi-modal-window") !== null'), true)
        ->keys('.fi-modal-window input[type="text"] >> nth=0', 'Escape');
    $page->assertScript(boardWaitFor('! document.querySelector(".fi-modal-open")'), true)
        ->assertScript(boardWaitFor('document.activeElement === document.querySelector(".fi-view-settings-trigger")'), true)
        ->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task, 'custom_fields.priority'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity, 'company'],
]);
