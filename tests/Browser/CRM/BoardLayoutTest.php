<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\CustomField;
use App\Models\Opportunity;
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
    $page->drag("[data-board-view-settings] [data-stage-id=\"{$ids[0]}\"] .fi-board-stage", "[data-board-view-settings] [data-stage-id=\"{$ids[1]}\"] .fi-board-stage");
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
