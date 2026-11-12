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
use App\Models\TablePreference;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TasksBoard::class, OpportunitiesBoard::class, MoveCardAction::class);

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

function dragInSteps(mixed $page, string $from, string $to): void
{
    if (! $page->script('navigator.userAgent.includes("Chrome")')) {
        test()->markTestSkipped('Playwright drives a WebKit drag through native drag events, which a pointer-based sortable never receives.');
    }

    $page->page()->locator($from)->dragTo($page->page()->locator($to), ['steps' => 25]);
}

const BOARD_COLUMN_IDS = '[...document.querySelectorAll(".ff-columns > .ff-column")].map((column) => column.dataset.columnRoot)';

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

    dragInSteps($page, "[data-column-root=\"{$ids[0]}\"] .ff-column-title", "[data-column-root=\"{$ids[1]}\"] .ff-column-title");
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

    dragInSteps($page, "[data-column-root=\"{$ids[2]}\"] .ff-column-title", "[data-column-root=\"{$ids[0]}\"] .ff-column-title");
    waitForStoredStageOrder($page, $user, $entity, [$ids[2], $ids[0], $ids[1], ...array_slice($ids, 3)]);

    dragInSteps($page, $card, "[data-column-root=\"{$to->getKey()}\"] .ff-cards");

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
        ->assertScript(BOARD_STAGE_ROW_IDS, $ids);

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
        ->click('.fi-view-settings-trigger');

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

    $page->click('.fi-board-stages-heading')->wait(0.5)->click($trigger(last($ids)))->wait(0.5);
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
        ->assertScript('(() => { const box = document.querySelector("[data-board-view-settings]").closest(".fi-dropdown-panel").getBoundingClientRect(); return box.left >= 0 && box.right <= innerWidth && box.bottom <= innerHeight; })()', true);

    $page->click("[data-board-view-settings] [data-stage-id=\"{$ids[1]}\"] [data-stage-actions]");
    $page->script('[...document.querySelectorAll(".fi-board-stages-row-menu .fi-dropdown-list-item")].find((item) => item.textContent.trim() === "Move up").click()');
    waitForStoredStageOrder($page, $user, $entity, [$ids[1], $ids[0], ...array_slice($ids, 2)]);

    expect(storedStageOrder($user, $entity))->toBe([$ids[1], $ids[0], ...array_slice($ids, 2)]);

    $page->assertScript(BOARD_COLUMN_IDS, [$ids[1], $ids[0], ...array_slice($ids, 2)])
        ->assertNoJavaScriptErrors();
})->with('stage order boards');
