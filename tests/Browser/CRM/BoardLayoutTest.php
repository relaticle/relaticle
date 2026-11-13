<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\BoardStateData;
use App\Enums\BoardColumnWidth;
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
use App\Models\TablePreference;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TasksBoard::class, OpportunitiesBoard::class, MoveCardAction::class);

pest()->group('webkit');

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
                thumbRoom: parseFloat(getComputedStyle(scroller).paddingRight),
            };
        })();
    JS;

    expect($page->script($overflow))->toEqual(['pageScrolls' => false, 'columnScrolls' => true, 'gapBelowCards' => 0, 'thumbRoom' => 12]);
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

it('shows checklist progress as a sized badge on a task card', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $statusField = CustomField::query()->forEntity(Task::class)->where('code', TaskField::STATUS->value)->firstOrFail();
    $descriptionField = CustomField::query()->forEntity(Task::class)->where('code', TaskField::DESCRIPTION->value)->firstOrFail();

    $task = Task::factory()->recycle([$user, $workspace])->create(['title' => 'Checklist card']);
    $task->saveCustomFieldValue($statusField, $statusField->options->first()->getKey());
    $task->saveCustomFieldValue($descriptionField, '<ul data-type="taskList">'
        .'<li data-checked="true" data-type="taskItem"><label><input type="checkbox" checked="checked"><span></span></label><div><p>Call Bob</p></div></li>'
        .'<li data-checked="false" data-type="taskItem"><label><input type="checkbox"><span></span></label><div><p>Send quote</p></div></li>'
        .'</ul>');

    TenantContextService::setTenantId(null);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/tasks/board")
        ->assertVisible("[data-card-id=\"{$task->getKey()}\"] [data-checklist-progress]")
        ->assertNoJavaScriptErrors();

    $badge = $page->script(<<<JS
        (() => {
            const badge = document.querySelector('[data-card-id="{$task->getKey()}"] [data-checklist-progress]');
            const icon = badge.querySelector('svg').getBoundingClientRect();

            return { text: badge.textContent.trim(), iconSized: icon.width > 0 && icon.height > 0 };
        })();
    JS);

    expect($badge)->toBe(['text' => '1/2', 'iconSized' => true]);
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

it('gives cards the same inset and gap in a flat column and in a lane cell', function (string $model, string $columnFieldCode, string $groupBy): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $columnField = CustomField::query()->forEntity($model)->where('code', $columnFieldCode)->firstOrFail();
    $firstColumn = $columnField->options->first();
    $company = Company::factory()->recycle([$user, $workspace])->create();

    $records = $model::factory()->recycle([$user, $workspace])->count(2)
        ->create($model === Opportunity::class ? ['company_id' => $company->getKey()] : []);

    $records->each(function (Task|Opportunity $record) use ($columnField, $firstColumn, $user): void {
        $record->saveCustomFieldValue($columnField, $firstColumn->getKey());

        if ($record instanceof Task) {
            $record->assignees()->attach($user);
        }
    });

    TenantContextService::setTenantId(null);

    $path = $model === Opportunity::class ? 'opportunities' : 'tasks';

    $flat = <<<'JS'
        (() => {
            const rect = (element) => element.getBoundingClientRect();
            const round = (value) => Math.round(value * 10) / 10;
            const column = document.querySelector('.ff-columns > .ff-column');
            const tray = rect(column.querySelector('.ff-tray'));
            const [first, second] = [...column.querySelectorAll('.ff-card')].map(rect);
            const heading = rect(column.querySelector('.ff-column-heading').firstElementChild);
            const actions = rect(column.querySelector('.ff-column-actions'));
            const addIcon = rect(column.querySelector('.ff-add-row-icon'));
            const cardPadding = parseFloat(getComputedStyle(column.querySelector('.ff-card-body')).paddingLeft);

            return {
                left: round(first.left - tray.left),
                right: round(tray.right - first.right),
                top: round(first.top - tray.top),
                gap: round(second.top - first.bottom),
                headerLeft: round(heading.left - first.left),
                headerRight: round(actions.right - first.right),
                addIcon: round(addIcon.left - first.left - cardPadding),
            };
        })();
    JS;

    $laneCell = <<<'JS'
        (() => {
            const rect = (element) => element.getBoundingClientRect();
            const round = (value) => Math.round(value * 10) / 10;
            const cell = [...document.querySelectorAll('.ff-cell')].find((candidate) => candidate.querySelectorAll('.ff-card').length === 2);
            const box = rect(cell);
            const [first, second] = [...cell.querySelectorAll('.ff-card')].map(rect);

            return {
                left: round(first.left - box.left),
                right: round(box.right - first.right),
                top: round(first.top - box.top),
                bottom: round(box.bottom - second.bottom),
                gap: round(second.top - first.bottom),
            };
        })();
    JS;

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/{$path}/board")
        ->assertVisible('.ff-columns > .ff-column .ff-card >> nth=1')
        ->assertNoJavaScriptErrors();

    $column = $page->script($flat);

    expect($column['left'])->toEqual(12)
        ->and($column['gap'])->toEqual(8)
        ->and($column['right'])->toBe($column['left'])
        ->and($column['top'])->toBe($column['left'])
        ->and($column['headerLeft'])->toBe(0)
        ->and($column['headerRight'])->toBe(0)
        ->and($column['addIcon'])->toBe(0);

    $page->navigate("/app/{$workspace->slug}/{$path}/board?groupBy={$groupBy}")
        ->assertVisible('.ff-cell .ff-card >> nth=1')
        ->assertNoJavaScriptErrors();

    $cell = $page->script($laneCell);

    expect($cell['left'])->toBe($column['left'])
        ->and($cell['right'])->toBe($column['left'])
        ->and($cell['top'])->toBe($column['left'])
        ->and($cell['bottom'])->toBe($column['left'])
        ->and($cell['gap'])->toBe($column['gap']);
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'assignee'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'company'],
]);

it('puts one row gap between the title and every row of a default card', function (string $model, string $columnFieldCode): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $columnField = CustomField::query()->forEntity($model)->where('code', $columnFieldCode)->firstOrFail();

    if ($model === Opportunity::class) {
        $company = Company::factory()->recycle([$user, $workspace])->create();
        $record = Opportunity::factory()->recycle([$user, $workspace])->create(['company_id' => $company->getKey()]);
        $amount = CustomField::query()->forEntity($model)->where('code', OpportunityField::AMOUNT->value)->firstOrFail();
        $closeDate = CustomField::query()->forEntity($model)->where('code', OpportunityField::CLOSE_DATE->value)->firstOrFail();
        $record->saveCustomFieldValue($amount, 5000);
        $record->saveCustomFieldValue($closeDate, now()->addWeek());
    } else {
        $record = Task::factory()->recycle([$user, $workspace])->create();
        $description = CustomField::query()->forEntity($model)->where('code', TaskField::DESCRIPTION->value)->firstOrFail();
        $record->saveCustomFieldValue($description, '<p>Short</p>');
        $record->assignees()->attach($user);
    }

    $record->saveCustomFieldValue($columnField, $columnField->options->first()->getKey());

    TenantContextService::setTenantId(null);

    $path = $model === Opportunity::class ? 'opportunities' : 'tasks';

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/{$path}/board")
        ->assertVisible("[data-card-id=\"{$record->getKey()}\"] .ff-card-body")
        ->assertNoJavaScriptErrors();

    $rows = $page->script(<<<JS
        (() => {
            const body = document.querySelector('[data-card-id="{$record->getKey()}"] .ff-card-body');
            const rows = [...body.querySelectorAll(':scope > .ff-card-title, :scope > .fi-sc > .fi-grid-col')]
                .map((row) => row.getBoundingClientRect())
                .filter((box) => box.height > 0);

            const margins = [...body.querySelectorAll('*:not(.fi-sr-only)')]
                .filter((element) => element.getBoundingClientRect().height > 0)
                .map((element) => parseFloat(getComputedStyle(element).marginTop) + parseFloat(getComputedStyle(element).marginBottom));

            return {
                rowGap: parseFloat(getComputedStyle(body).rowGap),
                gaps: rows.slice(1).map((box, index) => Math.round((box.top - rows[index].bottom) * 10) / 10),
                margin: Math.max(...margins),
            };
        })();
    JS);

    expect($rows['gaps'])->toHaveCount(2)
        ->and(array_unique($rows['gaps']))->toBe([$rows['rowGap']])
        ->and($rows['margin'])->toBe(0);
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value],
]);

it('gives an empty attribute its own line and labelled rows one gap', function (string $model, string $columnFieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $columnField = CustomField::query()->forEntity($model)->where('code', $columnFieldCode)->firstOrFail();

    if ($model === Opportunity::class) {
        $company = Company::factory()->recycle([$user, $workspace])->create();
        $record = Opportunity::factory()->recycle([$user, $workspace])->create(['company_id' => $company->getKey()]);
        $amount = CustomField::query()->forEntity($model)->where('code', OpportunityField::AMOUNT->value)->firstOrFail();
        $record->saveCustomFieldValue($amount, 5000);
    } else {
        $record = Task::factory()->recycle([$user, $workspace])->create();
        $priority = CustomField::query()->forEntity($model)->where('code', TaskField::PRIORITY->value)->firstOrFail();
        $record->saveCustomFieldValue($priority, $priority->options->first()->getKey());
        $record->assignees()->attach($user);
    }

    $record->saveCustomFieldValue($columnField, $columnField->options->first()->getKey());

    TenantContextService::setTenantId(null);

    $card = "[data-card-id=\"{$record->getKey()}\"]";
    $page = openBoard($user, $path)->assertVisible($card);

    $page->click('.fi-view-settings-trigger')->click('[data-board-view-settings] [data-board-hide-empty]');
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->hideEmpty === false);
    $page->assertScript(boardWaitFor("document.querySelector('{$card} .fi-board-card-row') !== null"), true);

    $spare = $page->script(<<<JS
        (() => {
            const body = document.querySelector('{$card} .ff-card-body');
            const style = getComputedStyle(body);
            const inner = body.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight);

            return [...body.querySelectorAll('.fi-board-card-row')]
                .map((row) => row.getBoundingClientRect())
                .filter((box) => box.height > 0)
                .map((box) => Math.round(inner - box.width));
        })();
    JS);

    expect($spare)->not->toBeEmpty()->and(array_unique($spare))->toBe([0]);

    $page->click('[data-board-view-settings] [data-board-show-labels]');
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->showLabels === true);
    $page->assertScript(boardWaitFor("document.querySelector('{$card} .ff-card-flex.gap-y-2')?.children.length > 1"), true);

    $rows = $page->script(<<<JS
        (() => {
            const list = document.querySelector('{$card} .ff-card-flex.gap-y-2');
            const boxes = [...list.children].map((row) => row.getBoundingClientRect()).filter((box) => box.height > 0);

            return {
                rowGap: parseFloat(getComputedStyle(list).rowGap),
                gaps: boxes.slice(1).map((box, index) => Math.round((box.top - boxes[index].bottom) * 10) / 10),
            };
        })();
    JS);

    expect($rows['rowGap'])->toEqual(8)
        ->and($rows['gaps'])->not->toBeEmpty()
        ->and(array_unique($rows['gaps']))->toEqual([8]);
    $page->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity],
]);

it('lets the next board column peek past the first on a 320px phone', function (string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(320, 568)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->assertVisible('.ff-column >> nth=0')
        ->assertNoJavaScriptErrors();

    expect($page->script('Math.round(document.querySelectorAll(".ff-column")[1].getBoundingClientRect().left)'))->toBeLessThanOrEqual(304);
})->with(['tasks/board', 'opportunities/board']);

it('moves a card on a phone by tapping a column in its move to sheet', function (string $model, string $columnFieldCode, string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $columnField = CustomField::query()->forEntity($model)->where('code', $columnFieldCode)->firstOrFail();
    [$from, $to] = [$columnField->options->get(0), $columnField->options->get(1)];

    $record = $model::factory()->recycle([$user, $workspace])->create();
    $record->saveCustomFieldValue($columnField, $from->getKey());

    TenantContextService::setTenantId(null);

    $card = "[data-card-id=\"{$record->getKey()}\"]";

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(430, 932)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->click("{$card} .fi-dropdown-trigger")
        ->click(".fi-dropdown-panel[style*='display: block'] .fi-dropdown-list-item:has-text('".__('filament/pages/boards.move_to.label')."')")
        ->assertVisible('.fi-modal-open .fi-modal-window')
        ->wait(0.5)
        ->assertScript("(() => { const r = document.querySelector('.fi-modal-open .fi-modal-window').getBoundingClientRect(); return r.left === 0 && Math.round(r.right) === innerWidth; })()", true)
        ->assertScript("document.querySelector('.fi-modal-open [role=radio][aria-checked=true]').textContent.trim()", $from->name)
        ->click(".fi-modal-open [role=radio]:has-text('{$to->name}')")
        ->assertSee(__('filament/pages/boards.move_to.moved', ['column' => $to->name]))
        ->assertScript("document.querySelector('{$card}').closest('[data-column-root]').dataset.columnRoot", (string) $to->getKey())
        ->assertScript("document.querySelector('.fi-modal-open') === null", true)
        ->assertNoJavaScriptErrors();

    expect($record->fresh()->customFieldValues()->where('custom_field_id', $columnField->getKey())->value($columnField->getValueColumn()))
        ->toBe($to->getKey());
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board'],
]);

it('swaps the open preview for the next card instead of stacking a second one', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $stageField = CustomField::query()->forEntity(Opportunity::class)->where('code', OpportunityField::STAGE->value)->firstOrFail();
    [$first, $second] = Opportunity::factory()->recycle([$user, $workspace])->count(2)->sequence(['name' => 'Rooftop pilot'], ['name' => 'Fleet renewal'])->create()->all();
    $first->saveCustomFieldValue($stageField, $stageField->options->first()->getKey());
    $second->saveCustomFieldValue($stageField, $stageField->options->get(1)->getKey());

    TenantContextService::setTenantId(null);

    $openPreviews = 'document.querySelectorAll(".fi-modal-window.fi-record-preview").length';

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/opportunities/board")
        ->hover("[data-card-id=\"{$first->getKey()}\"]")
        ->click("[data-card-id=\"{$first->getKey()}\"] .fi-board-card-preview-trigger")
        ->assertSee('Rooftop pilot')
        ->hover("[data-card-id=\"{$second->getKey()}\"]")
        ->click("[data-card-id=\"{$second->getKey()}\"] .fi-board-card-preview-trigger")
        ->wait(1)
        ->assertScript($openPreviews, 1)
        ->assertScript('document.querySelector(".fi-modal-window.fi-record-preview .fi-record-preview-title").innerText.trim()', 'Fleet renewal')
        ->assertNoJavaScriptErrors();
});

dataset('stage order boards', [
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity],
]);

/**
 * @return list<string>
 */
function boardStageOptionIds(string $model, string $fieldCode): array
{
    return CustomField::query()->withoutGlobalScopes()->where('entity_type', (new $model)->getMorphClass())->where('code', $fieldCode)->firstOrFail()
        ->options()->withoutGlobalScopes()->orderBy('sort_order')->pluck('id')->map(strval(...))->all();
}

/**
 * @return ?list<string>
 */
function storedStageOrder(User $user, CrmEntity $entity): ?array
{
    return TablePreference::query()->withoutGlobalScopes()->whereBelongsTo($user)->where('entity', $entity)->first()?->board_state->stageOrder;
}

function waitForStoredStageOrder(mixed $page, User $user, CrmEntity $entity, array $expected): void
{
    for ($attempt = 0; $attempt < 50 && storedStageOrder($user, $entity) !== $expected; $attempt++) {
        $page->wait(0.1);
    }
}

const BOARD_COLUMN_IDS = '(async () => { const read = () => [...document.querySelectorAll(".ff-columns > .ff-column")].map((column) => column.dataset.columnRoot).join(","); let last = read(); let stable = 0; for (let attempt = 0; attempt < 50 && stable < 3; attempt++) { await new Promise((resolve) => setTimeout(resolve, 100)); const now = read(); stable = now === last && now !== "" ? stable + 1 : 0; last = now; } return last === "" ? [] : last.split(","); })()';

const BOARD_STAGE_ROW_IDS = '[...document.querySelectorAll("[data-board-view-settings] [data-stage-id]")].map((row) => row.dataset.stageId)';

it('drags a column header to reorder the columns and keeps the order after a reload', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $ids = boardStageOptionIds($model, $fieldCode);
    $expected = [$ids[1], $ids[0], ...array_slice($ids, 2)];

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->assertVisible('.ff-column >> nth=0')
        ->assertScript(BOARD_COLUMN_IDS, $ids);

    dragWithPointerEvents($page, "[data-column-root=\"{$ids[0]}\"] .ff-column-title", "[data-column-root=\"{$ids[1]}\"] .ff-column-title");
    waitForStoredStageOrder($page, $user, $entity, $expected);

    expect(storedStageOrder($user, $entity))->toBe($expected);

    $page->navigate("/app/{$workspace->slug}/{$path}")
        ->assertVisible('.ff-column >> nth=0')
        ->assertScript(BOARD_COLUMN_IDS, $expected)
        ->assertNoJavaScriptErrors();
})->with('stage order boards');

it('still moves a card between columns after the columns were reordered', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    [$from, $to] = [$field->options->get(0), $field->options->get(1)];
    $record = $model::factory()->recycle([$user, $workspace])->create();
    $record->saveCustomFieldValue($field, $from->getKey());

    TenantContextService::setTenantId(null);

    $ids = boardStageOptionIds($model, $fieldCode);
    $card = "[data-card-id=\"{$record->getKey()}\"]";

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->assertVisible($card);

    dragWithPointerEvents($page, "[data-column-root=\"{$ids[2]}\"] .ff-column-title", "[data-column-root=\"{$ids[0]}\"] .ff-column-title");
    waitForStoredStageOrder($page, $user, $entity, [$ids[2], $ids[0], $ids[1], ...array_slice($ids, 3)]);

    expect(storedStageOrder($user, $entity))->toBe([$ids[2], $ids[0], $ids[1], ...array_slice($ids, 3)]);

    dragCard($page, $card, "[data-column-root=\"{$to->getKey()}\"] .ff-cards");

    for ($attempt = 0; $attempt < 50 && $record->fresh()->customFieldValues()->where('custom_field_id', $field->getKey())->value($field->getValueColumn()) !== $to->getKey(); $attempt++) {
        $page->wait(0.1);
    }

    expect($record->fresh()->customFieldValues()->where('custom_field_id', $field->getKey())->value($field->getValueColumn()))->toBe($to->getKey());

    $page->assertScript("document.querySelector('{$card}').closest('[data-column-root]').dataset.columnRoot", (string) $to->getKey())
        ->assertNoJavaScriptErrors();
})->with('stage order boards');

it('lists the stages in the board view settings and reorders them by dragging a row', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $ids = boardStageOptionIds($model, $fieldCode);
    $expected = [$ids[1], $ids[0], ...array_slice($ids, 2)];

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->assertVisible('.fi-view-settings-trigger')
        ->click('.fi-view-settings-trigger')
        ->assertVisible('[data-board-view-settings]')
        ->click('[data-board-columns-trigger]')
        ->assertScript(BOARD_STAGE_ROW_IDS, $ids);

    $page->assertScript('(async () => { const bound = () => Object.keys(document.querySelector("[data-board-view-settings] .fi-ta-views-list")).some((key) => key.startsWith("Sortable")); for (let attempt = 0; ! bound() && attempt < 50; attempt++) { await new Promise((resolve) => setTimeout(resolve, 100)); } return bound(); })()', true);
    $page->page()->locator("[data-board-view-settings] [data-stage-id=\"{$ids[0]}\"] .fi-board-stage")
        ->dragTo($page->page()->locator("[data-board-view-settings] [data-stage-id=\"{$ids[1]}\"] .fi-board-stage"), ['steps' => 10]);
    waitForStoredStageOrder($page, $user, $entity, $expected);

    expect(storedStageOrder($user, $entity))->toBe($expected);

    $page->assertScript(BOARD_COLUMN_IDS, $expected)
        ->assertNoJavaScriptErrors();
})->with('stage order boards');

it('moves a stage from its menu, keeps focus on its menu button and disables the moves with nowhere to go', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $ids = boardStageOptionIds($model, $fieldCode);
    $item = fn (string $label): string => '[...document.querySelectorAll(".fi-board-stages-row-menu .fi-dropdown-list-item")].find((item) => item.textContent.trim() === "'.$label.'")';
    $trigger = fn (string $id): string => "[data-board-view-settings] [data-stage-id=\"{$id}\"] [data-stage-actions]";

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->click('.fi-view-settings-trigger')
        ->click('[data-board-columns-trigger]');

    $page->click($trigger($ids[0]))->wait(0.5);
    $page->assertScript($item('Move up').'.disabled', true)
        ->assertScript($item('Move down').'.disabled', false);

    $page->script($item('Move down').'.click()');
    waitForStoredStageOrder($page, $user, $entity, [$ids[1], $ids[0], ...array_slice($ids, 2)]);

    $page->assertScript(BOARD_STAGE_ROW_IDS, [$ids[1], $ids[0], ...array_slice($ids, 2)])
        ->assertScript(BOARD_COLUMN_IDS, [$ids[1], $ids[0], ...array_slice($ids, 2)])
        ->assertScript("document.activeElement === document.querySelector('{$trigger($ids[0])}')", true);

    $page->wait(0.5)->click($trigger($ids[0]))->wait(0.5);
    $page->assertScript($item('Move up').'.disabled', false);

    $page->click('[data-board-panel="columns"] .fi-board-panel-title')->wait(0.5)->click($trigger(last($ids)))->wait(0.5);
    $page->assertScript($item('Move down').'.disabled', true)
        ->assertNoJavaScriptErrors();
})->with('stage order boards');

it('keeps the board view settings usable on a 390px phone', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $ids = boardStageOptionIds($model, $fieldCode);

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$workspace->slug}/{$path}")
        ->click('.fi-view-settings-trigger')
        ->assertVisible('[data-board-view-settings]')
        ->assertScript('(() => { const box = document.querySelector("[data-board-view-settings]").closest(".fi-dropdown-panel").getBoundingClientRect(); return box.left >= 0 && box.right <= innerWidth && box.bottom <= innerHeight; })()', true)
        ->click('[data-board-columns-trigger]');

    $page->click("[data-board-view-settings] [data-stage-id=\"{$ids[1]}\"] [data-stage-actions]");
    $page->script('[...document.querySelectorAll(".fi-board-stages-row-menu .fi-dropdown-list-item")].find((item) => item.textContent.trim() === "Move up").click()');
    waitForStoredStageOrder($page, $user, $entity, [$ids[1], $ids[0], ...array_slice($ids, 2)]);

    expect(storedStageOrder($user, $entity))->toBe([$ids[1], $ids[0], ...array_slice($ids, 2)]);

    $page->assertScript(BOARD_COLUMN_IDS, [$ids[1], $ids[0], ...array_slice($ids, 2)])
        ->assertNoJavaScriptErrors();
})->with('stage order boards');

function dragWithPointerEvents(mixed $page, string $from, string $to): void
{
    $started = $page->script(sprintf(<<<'JS'
        (() => {
            if (window.boardDrag === 'running') {
                return false;
            }

            window.boardDrag = 'running';

            (async () => {
                const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

                if (! window.boardDragRequests) {
                    window.boardDragRequests = { pending: 0 };
                    window.Livewire.hook('request', ({ succeed, fail }) => {
                        const done = () => setTimeout(() => window.boardDragRequests.pending--, 300);

                        window.boardDragRequests.pending++;
                        succeed(done);
                        fail(done);
                    });
                }

                const idle = async () => {
                    for (let attempt = 0; window.boardDragRequests.pending > 0 && attempt < 100; attempt++) {
                        await wait(100);
                    }
                };

                await idle();

                const columns = document.querySelector(%1$s).closest('.ff-columns, .ff-lanes-heads');

                for (let attempt = 0; columns && ! window.Sortable.get(columns) && attempt < 50; attempt++) {
                    await wait(100);
                }

                const centre = (selector) => {
                    const box = document.querySelector(selector).getBoundingClientRect();

                    return [box.left + box.width / 2, box.top + box.height / 2];
                };
                const [fromX, fromY] = centre(%1$s);
                const [toX, toY] = centre(%2$s);
                const pointerEvents = navigator.userAgent.includes('Chrome');
                const press = (name, x, y, target = document) => {
                    const type = (pointerEvents ? 'pointer' : 'mouse') + name;
                    const init = { bubbles: true, cancelable: true, button: 0, buttons: name === 'up' ? 0 : 1, clientX: x, clientY: y };

                    target.dispatchEvent(pointerEvents ? new PointerEvent(type, { ...init, pointerId: 1, isPrimary: true, pointerType: 'mouse' }) : new MouseEvent(type, init));
                };
                const source = document.querySelector(%1$s).closest('.ff-column');
                const position = () => source?.parentElement ? [...source.parentElement.children].indexOf(source) : -1;
                const target = document.querySelector(%2$s).closest('.ff-column');
                const goal = target?.parentElement ? [...target.parentElement.children].indexOf(target) : -1;

                press('down', fromX, fromY, document.querySelector(%1$s));

                for (let step = 1; step <= 20; step++) {
                    press('move', fromX + (toX - fromX) * step / 20, fromY + (toY - fromY) * step / 20);
                    await wait(30);
                }

                for (let attempt = 0; position() !== goal && attempt < 30; attempt++) {
                    press('move', toX + (attempt %% 2 ? 2 : -2), toY);
                    await wait(100);
                }

                await wait(100);
                press('up', toX, toY);
                await wait(100);
                await idle();
            })().then(() => { window.boardDrag = 'done'; }, (error) => { window.boardDrag = 'failed: ' + error; });

            return true;
        })()
        JS, json_encode($from), json_encode($to)));

    for ($attempt = 0; $attempt < 300 && $page->script('window.boardDrag') === 'running'; $attempt++) {
        $page->wait(0.1);
    }

    expect($started)->toBeTrue()
        ->and($page->script('window.boardDrag'))->toBe('done');
}

function dragCard(mixed $page, string $from, string $to): void
{
    if (! $page->script('navigator.userAgent.includes("Chrome")')) {
        test()->markTestSkipped('Flowforge tracks a card drag through the pointer, and synthetic events never move one in WebKit.');
    }

    $page->page()->locator($from)->dragTo($page->page()->locator($to), ['steps' => 25]);
}

function boardHeaderTitle(string $id, string $container = '.ff-columns'): string
{
    return "{$container} > [data-column-root=\"{$id}\"] .ff-column-title";
}

function openBoard(User $user, string $path, int $width = 1440, string $query = ''): mixed
{
    $workspace = $user->ownedWorkspaces()->first();

    return loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize($width, 900)
        ->navigate("/app/{$workspace->slug}/{$path}{$query}")
        ->assertVisible('.ff-column >> nth=0');
}

it('keeps header dragging alive after the page re-renders', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $first = [$ids[1], $ids[0], ...array_slice($ids, 2)];
    $second = [$ids[2], $ids[1], $ids[0], ...array_slice($ids, 3)];
    $third = [$ids[0], $ids[2], $ids[1], ...array_slice($ids, 3)];

    $page = openBoard($user, $path);

    dragWithPointerEvents($page, boardHeaderTitle($ids[0]), boardHeaderTitle($ids[1]));
    waitForStoredStageOrder($page, $user, $entity, $first);
    expect(storedStageOrder($user, $entity))->toBe($first);

    $page->wait(1);
    dragWithPointerEvents($page, boardHeaderTitle($ids[2]), boardHeaderTitle($ids[1]));
    waitForStoredStageOrder($page, $user, $entity, $second);
    expect(storedStageOrder($user, $entity))->toBe($second);

    $page->wait(1);
    dragWithPointerEvents($page, boardHeaderTitle($ids[0]), boardHeaderTitle($ids[2]));
    waitForStoredStageOrder($page, $user, $entity, $third);
    expect(storedStageOrder($user, $entity))->toBe($third);

    $page->assertScript('document.querySelectorAll(".ff-columns > .ff-column").length', count($ids))
        ->assertNoJavaScriptErrors();
})->with('stage order boards');

it('keeps header dragging alive after a card moved and after a card was opened', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    $record = $model::factory()->recycle([$user, $workspace])->create();
    $record->saveCustomFieldValue($field, $field->options->get(0)->getKey());

    TenantContextService::setTenantId(null);

    $ids = boardStageOptionIds($model, $fieldCode);
    $card = "[data-card-id=\"{$record->getKey()}\"]";
    $first = [$ids[1], $ids[0], ...array_slice($ids, 2)];
    $second = [$ids[2], $ids[1], $ids[0], ...array_slice($ids, 3)];
    $third = [$ids[0], $ids[2], $ids[1], ...array_slice($ids, 3)];

    $page = openBoard($user, $path)->assertVisible($card);

    dragWithPointerEvents($page, boardHeaderTitle($ids[0]), boardHeaderTitle($ids[1]));
    waitForStoredStageOrder($page, $user, $entity, $first);

    dragCard($page, $card, "[data-column-root=\"{$ids[1]}\"] .ff-cards");
    $page->wait(1.5);

    dragWithPointerEvents($page, boardHeaderTitle($ids[2]), boardHeaderTitle($ids[1]));
    waitForStoredStageOrder($page, $user, $entity, $second);
    expect(storedStageOrder($user, $entity))->toBe($second);

    $page->click($card)->assertVisible('.fi-modal-window')->wait(0.5);
    $page->script('document.querySelector(".fi-modal-window .fi-modal-close-btn")?.click()');
    $page->wait(1);

    dragWithPointerEvents($page, boardHeaderTitle($ids[0]), boardHeaderTitle($ids[2]));
    waitForStoredStageOrder($page, $user, $entity, $third);
    expect(storedStageOrder($user, $entity))->toBe($third);
})->with('stage order boards');

it('keeps a dragged column among the columns and never stores a partial order', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);

    $page = openBoard($user, $path);

    dragWithPointerEvents($page, boardHeaderTitle($ids[0]), "[data-column-root=\"{$ids[1]}\"] .ff-cards");
    $page->wait(2);

    $page->assertScript('document.querySelectorAll(".ff-columns > .ff-column").length', count($ids))
        ->assertScript('document.querySelectorAll(".ff-cards .ff-column, .ff-tray .ff-column").length', 0)
        ->assertScript('[...document.querySelectorAll(".ff-columns > .ff-column")].map((column) => column.dataset.columnRoot).sort().join()', collect($ids)->sort()->implode(','))
        ->assertNoJavaScriptErrors();

    $stored = storedStageOrder($user, $entity);

    expect($stored === null || (count($stored) === count($ids) && collect($stored)->sort()->values()->all() === collect($ids)->sort()->values()->all()))->toBeTrue();
})->with('stage order boards');

it('keeps header dragging alive after leaving the board and coming back', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $expected = [$ids[1], $ids[0], ...array_slice($ids, 2)];

    $page = openBoard($user, $path)
        ->click('.fi-view-switcher .fi-dropdown-trigger button')
        ->click('.fi-ta-views-row[data-view-key="list"] .fi-dropdown-list-item')
        ->assertVisible('.fi-ta-table');

    $page->script('history.back()');
    $page->assertVisible('.ff-column >> nth=0')->wait(1);

    dragWithPointerEvents($page, boardHeaderTitle($ids[0]), boardHeaderTitle($ids[1]));
    waitForStoredStageOrder($page, $user, $entity, $expected);

    expect(storedStageOrder($user, $entity))->toBe($expected);
})->with('stage order boards');

it('puts the columns back and says so when the order cannot be saved', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);

    $page = openBoard($user, $path);
    $page->script('window.fetch = ((original) => (input, init) => String(init?.body).includes("reorderBoardStages") ? Promise.resolve(new Response("{}", { status: 500 })) : original(input, init))(window.fetch)');

    dragWithPointerEvents($page, boardHeaderTitle($ids[0]), boardHeaderTitle($ids[1]));
    $page->wait(2);

    $page->assertScript(BOARD_COLUMN_IDS, $ids)
        ->assertScript('[...document.querySelectorAll(".fi-no-notification")].some((notification) => notification.textContent.includes('.json_encode(__('filament/pages/boards.stage_order_failed')).'))', true);

    expect(storedStageOrder($user, $entity))->toBeNull();
})->with('stage order boards');

it('starts no drag and saves nothing from a column count or its add button', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);

    $page = openBoard($user, $path);

    foreach (['.ff-count', '.ff-column-actions button'] as $handle) {
        dragWithPointerEvents($page, "[data-column-root=\"{$ids[0]}\"] {$handle}", boardHeaderTitle($ids[1]));
        $page->wait(1);
    }

    $page->assertScript(BOARD_COLUMN_IDS, $ids);

    expect(storedStageOrder($user, $entity))->toBeNull();
})->with('stage order boards');

it('reorders the columns of a grouped board by their header', function (string $model, string $fieldCode, string $path, CrmEntity $entity, string $groupBy): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $expected = [$ids[1], $ids[0], ...array_slice($ids, 2)];

    $page = openBoard($user, $path, 1440, "?groupBy={$groupBy}")
        ->assertVisible('.ff-lanes-heads');

    dragWithPointerEvents($page, boardHeaderTitle($ids[0], '.ff-lanes-heads'), boardHeaderTitle($ids[1], '.ff-lanes-heads'));
    waitForStoredStageOrder($page, $user, $entity, $expected);

    expect(storedStageOrder($user, $entity))->toBe($expected);

    $page->wait(1)->assertScript('[...document.querySelectorAll(".ff-lanes-heads > .ff-column")].map((column) => column.dataset.columnRoot)', $expected);
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task, 'assignee'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity, 'company'],
]);

it('returns focus to the stage menu button when Escape closes its row menu', function (string $model, string $fieldCode, string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $trigger = "[data-board-view-settings] [data-stage-id=\"{$ids[1]}\"] [data-stage-actions]";

    $page = openBoard($user, $path)
        ->click('.fi-view-settings-trigger')
        ->click('[data-board-columns-trigger]')
        ->click($trigger)
        ->wait(0.5)
        ->keys($trigger, 'Escape')
        ->wait(0.5);

    $page->assertScript("document.activeElement === document.querySelector('{$trigger}')", true)
        ->assertVisible('[data-board-view-settings]')
        ->assertNoJavaScriptErrors();

    $page->keys($trigger, 'Enter')->wait(0.5)->assertScript('document.activeElement.closest(".fi-board-stages-row-menu") !== null', true);
    $page->keys(':focus', 'Escape')->wait(0.5);

    $page->assertScript("document.activeElement === document.querySelector('{$trigger}')", true)
        ->assertVisible('[data-board-view-settings]');
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board'],
]);

function boardCardCount(mixed $page, int $count): bool
{
    return $page->script("(async () => { for (let attempt = 0; attempt < 80; attempt++) { if (document.querySelectorAll('.ff-card').length === {$count}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()");
}

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

function openViewSettingsPanel(mixed $page, string $trigger): void
{
    $page->click('.fi-view-settings-trigger')->assertVisible('[data-board-view-settings]')->click($trigger);
}

function closeViewSettings(mixed $page): void
{
    $page->click('.fi-topbar-page-title')
        ->assertScript(boardWaitFor('! document.querySelector("[data-board-view-settings]").checkVisibility()'), true);
}

function waitForBoardColumns(mixed $page, array $expected): void
{
    $page->assertScript(BOARD_COLUMN_IDS, $expected);
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

function storedBoardState(User $user, CrmEntity $entity): ?BoardStateData
{
    return TablePreference::query()->withoutGlobalScopes()->whereBelongsTo($user)->where('entity', $entity)->first()?->board_state;
}

function waitForPage(mixed $page, Closure $done): void
{
    for ($attempt = 0; $attempt < 50 && ! $done(); $attempt++) {
        $page->wait(0.1);
    }
}

function settingsPanel(): string
{
    return 'window.Alpine.$data(document.querySelector("[data-board-view-settings]")).panel';
}

const BOARD_ATTRIBUTE_KEYS = '[...document.querySelectorAll("[data-board-view-settings] [data-card-attribute]")].map((row) => row.dataset.cardAttribute)';

function pickRowMenuItem(mixed $page, string $trigger, string $label): void
{
    $page->click($trigger)->wait(0.5);
    $page->script('[...document.querySelectorAll(".fi-board-stages-row-menu .fi-dropdown-list-item")].find((item) => item.textContent.trim() === "'.$label.'").click()');
}

function dragSettingsRow(mixed $page, string $from, string $to): void
{
    $page->wait(0.5);
    $page->page()->locator($from)->dragTo($page->page()->locator($to), ['steps' => 10]);
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

function boardLayoutIs(string $attribute, string $value): string
{
    return "(async () => { for (let attempt = 0; attempt < 50; attempt++) { if (document.querySelector('.fi-board-header').dataset.{$attribute} === '{$value}') { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()";
}

function boardSize(string $column, string $card): string
{
    return "(async () => { await new Promise((resolve) => setTimeout(resolve, 300)); return { column: Math.round(document.querySelector('{$column}').getBoundingClientRect().width), card: Math.round(document.querySelector('{$card}').getBoundingClientRect().height) }; })()";
}

function pressLayout(mixed $page, string $kind, string $option, string $attribute): void
{
    $page->click("[data-board-{$kind}-option=\"{$option}\"]")
        ->assertScript(boardLayoutIs($attribute, $option), true)
        ->assertScript("document.querySelector('[data-board-{$kind}-option=\"{$option}\"]').getAttribute('aria-pressed')", 'true');
}

it('narrows columns and compacts cards flat and with lanes, every time it is pressed, and keeps phones wide', function (string $model, string $fieldCode, string $path, CrmEntity $entity, string $groupBy): void {
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
    $wide = $page->script($flat);

    pressLayout($page, 'column-width', 'narrow', 'boardColumnWidth');
    $narrow = $page->script($flat);
    expect($narrow['column'])->toBeLessThan($wide['column']);

    pressLayout($page, 'column-width', 'wide', 'boardColumnWidth');
    expect($page->script($flat))->toBe($wide);

    pressLayout($page, 'column-width', 'narrow', 'boardColumnWidth');
    expect($page->script($flat))->toBe($narrow);

    pressLayout($page, 'card-size', 'compact', 'boardCardSize');
    $compact = $page->script($flat);
    expect($compact['card'])->toBeLessThan($narrow['card'])->and($compact['column'])->toBe($narrow['column']);

    pressLayout($page, 'card-size', 'full', 'boardCardSize');
    expect($page->script($flat))->toBe($narrow);

    pressLayout($page, 'card-size', 'compact', 'boardCardSize');
    expect($page->script($flat))->toBe($compact);

    openViewSettingsPanel($page, '[data-board-lanes-trigger]');
    $page->click("[data-board-grouping=\"{$groupBy}\"]")->assertVisible('.ff-lanes-heads');
    closeViewSettings($page);

    $laneCompact = $page->script($lanes);
    expect($laneCompact['column'])->toBe($narrow['column']);
    $page->script('(() => { const press = (value) => document.querySelector(`[data-board-column-width-option="${value}"]`).click(); press("narrow"); setTimeout(() => press("wide"), 60); })()');
    waitForPage($page, fn (): bool => storedBoardState($user, $entity)?->columnWidth === BoardColumnWidth::Wide);
    expect(storedBoardState($user, $entity)?->columnWidth)->toBe(BoardColumnWidth::Wide);
    $page->assertScript(boardLayoutIs('boardColumnWidth', 'wide'), true);
    expect($page->script($flat))->toBe($wide);


    pressLayout($page, 'column-width', 'wide', 'boardColumnWidth');
    $laneWide = $page->script($lanes);
    expect($laneWide['column'])->toBe($wide['column']);

    pressLayout($page, 'column-width', 'narrow', 'boardColumnWidth');
    expect($page->script($lanes))->toBe($laneCompact);

    pressLayout($page, 'card-size', 'full', 'boardCardSize');
    expect($page->script($lanes)['card'])->toBeGreaterThan($laneCompact['card']);

    $page->resize(390, 844)->navigate("/app/{$workspace->slug}/{$path}")->assertVisible('.ff-column >> nth=0');
    $page->assertScript(boardLayoutIs('boardColumnWidth', 'narrow'), true);
    expect($page->script('Math.round(document.querySelector(".ff-lanes-heads > .ff-column, .ff-columns > .ff-column").getBoundingClientRect().width)'))->toBe($phone['column']);
    expect($page->script('Math.round(document.querySelectorAll(".ff-lanes-heads > .ff-column, .ff-columns > .ff-column")[1].getBoundingClientRect().left)'))->toBeLessThan(390);
    $page->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task, 'assignee'],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity, 'company'],
]);

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

function boardWaitFor(string $condition): string
{
    return "(async () => { for (let attempt = 0; attempt < 50; attempt++) { if ({$condition}) { return true; } await new Promise((resolve) => setTimeout(resolve, 100)); } return false; })()";
}

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
