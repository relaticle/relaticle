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

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}", 1440, 900)
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

    $page = visitAs($user, "/app/{$workspace->slug}/tasks/board", 1440, 900)
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

    $page = visitAs($user, "/app/{$workspace->slug}/tasks/board")
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

    $page = visitAs($user, "/app/{$workspace->slug}/opportunities/board", 1440, 900)
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

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}/board", 1440, 900)
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

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}/board", 1440, 900)
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

it('gives an empty attribute its own line and labelled rows one gap, one line and room for their values', function (string $model, string $columnFieldCode, string $path, CrmEntity $entity, array $inRows): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    TenantContextService::setTenantId($workspace->getKey());

    $columnField = CustomField::query()->forEntity($model)->where('code', $columnFieldCode)->firstOrFail();

    if ($model === Opportunity::class) {
        $company = Company::factory()->recycle([$user, $workspace])->create();
        $record = Opportunity::factory()->recycle([$user, $workspace])->create(['company_id' => $company->getKey()]);
        $amount = CustomField::query()->forEntity($model)->where('code', OpportunityField::AMOUNT->value)->firstOrFail();
        $record->saveCustomFieldValue($amount, 20000);
    } else {
        $record = Task::factory()->recycle([$user, $workspace])->create();
        $priority = CustomField::query()->forEntity($model)->where('code', TaskField::PRIORITY->value)->firstOrFail();
        $description = CustomField::query()->forEntity($model)->where('code', TaskField::DESCRIPTION->value)->firstOrFail();
        $record->saveCustomFieldValue($priority, $priority->options->first()->getKey());
        $record->saveCustomFieldValue($description, '<p>Short</p>');
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

    $page->click('.fi-topbar-page-title');
    pressLayout($page, 'column-width', 'narrow', 'boardColumnWidth');

    $labelled = $page->script(<<<JS
        (() => {
            const rows = [...document.querySelectorAll('{$card} .fi-board-card-row')].filter((row) => row.getBoundingClientRect().height > 0);

            return {
                labels: rows.map((row) => row.querySelector('.fi-sc-text').textContent.trim()),
                cut: rows.filter((row) => [...row.querySelectorAll('.fi-badge, img')].some((value) => value.getBoundingClientRect().right > row.getBoundingClientRect().right + 0.5)).length,
                apart: rows.filter((row) => {
                    const [label, value] = [row.querySelector('.fi-sc-text'), row.querySelector('.fi-badge, img') ?? row.children[1]].map((part) => part.getBoundingClientRect());

                    return Math.abs((label.top + label.bottom) - (value.top + value.bottom)) / 2 > 2;
                }).length,
            };
        })();
    JS);

    expect($labelled['cut'])->toBe(0)
        ->and($labelled['apart'])->toBe(0)
        ->and($labelled['labels'])->toContain(...$inRows)
        ->and($labelled['labels'])->not->toContain('Description');
    $page->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, TaskField::STATUS->value, 'tasks/board', CrmEntity::Task, ['Priority', 'Assignees']],
    'opportunities' => [Opportunity::class, OpportunityField::STAGE->value, 'opportunities/board', CrmEntity::Opportunity, ['Company', 'Amount']],
]);

it('lets the next board column peek past the first on a 320px phone', function (string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}", 320, 568)
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

    visitAs($user, "/app/{$workspace->slug}/{$path}", 430, 932)
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

    visitAs($user, "/app/{$workspace->slug}/opportunities/board", 1440, 900)
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

it('drags a column header to reorder the columns and keeps the order after a reload', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $ids = boardStageOptionIds($model, $fieldCode);
    $expected = [$ids[1], $ids[0], ...array_slice($ids, 2)];

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}", 1440, 900)
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

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}", 1440, 900)
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

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}", 1440, 900)
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

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}", 1440, 900)
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

    $page = visitAs($user, "/app/{$workspace->slug}/{$path}", 390, 844)
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
