<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\OptionColor;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TasksBoard::class, OpportunitiesBoard::class, MoveCardAction::class);
pest()->group('webkit');

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

it('opens the stage menu on a header click, renames on Enter and recolors from the swatch grid', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $menu = "[data-stage-menu=\"{$ids[1]}\"]";
    $option = fn (): CustomFieldOption => CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($ids[1]);

    $page = openBoard($user, $path);

    $page->page()->locator(boardHeaderTitle($ids[1]))->click();
    $page->assertVisible("{$menu} [data-stage-name-input]");

    $input = $page->page()->locator("{$menu} [data-stage-name-input]");
    $input->fill('Discovery');
    $input->press('Enter');
    waitForPage($page, fn (): bool => $option()->name === 'Discovery');

    expect($option()->name)->toBe('Discovery');
    $page->assertScript(boardWaitFor('document.querySelector(\''.boardHeaderTitle($ids[1]).'\').textContent.trim() === "Discovery"'), true);

    $input->fill('Discovery call');
    $input->press('Enter');
    waitForPage($page, fn (): bool => $option()->name === 'Discovery call');

    expect($option()->name)->toBe('Discovery call')
        ->and($page->script("document.querySelector('{$menu} [data-stage-error]')"))->toBeNull();

    $page->page()->locator("{$menu} [data-stage-color-trigger]")->click();
    $page->assertVisible("{$menu} [data-stage-swatches]");
    $pink = OptionColor::Pink->value;
    $page->page()->locator("{$menu} [data-swatch=\"{$pink}\"]")->click();
    waitForPage($page, fn (): bool => $option()->settings->color === $pink);

    expect($option()->settings->color)->toBe($pink);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('moves focus into the swatch grid when the stage menu dot opens it, so arrow keys recolor', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $menu = "[data-stage-menu=\"{$ids[1]}\"]";
    $option = fn (): CustomFieldOption => CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($ids[1]);
    $before = $option()->settings->color;

    $page = openBoard($user, $path);

    $page->page()->locator(boardHeaderTitle($ids[1]))->click();
    $page->page()->locator("{$menu} [data-stage-color-trigger]")->click();
    $page->assertScript(boardWaitFor("document.activeElement?.closest('{$menu} [data-stage-swatches]') !== null"), true);

    $page->keys("{$menu} .ff-swatch[tabindex=\"0\"]", 'ArrowRight');
    $page->keys("{$menu} .ff-swatch[tabindex=\"0\"]", 'Enter');
    waitForPage($page, fn (): bool => $option()->settings->color !== $before);

    expect($option()->settings->color)->not->toBe($before);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('keeps the stage open and its menu up on a double-click on its header, with the name focused', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $menu = "[data-stage-menu=\"{$ids[1]}\"]";

    $page = openBoard($user, $path);

    $page->page()->locator(boardHeaderTitle($ids[1]))->dblclick();
    $page->wait(1);

    $page->assertVisible("{$menu} [data-stage-name-input]")
        ->assertScript("document.activeElement === document.querySelector('{$menu} [data-stage-name-input]')", true)
        ->assertScript('document.querySelectorAll(".ff-column[data-collapsed]").length', 0);

    expect(storedBoardState($user, $entity)?->collapsedColumns ?? [])->toBe([]);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('selects a word on a double-click in the stage name input and does not collapse the column', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $menu = "[data-stage-menu=\"{$ids[1]}\"]";

    $page = openBoard($user, $path);

    $page->page()->locator(boardHeaderTitle($ids[1]))->click();

    $input = $page->page()->locator("{$menu} [data-stage-name-input]");
    $input->fill('Warm lead');
    $input->dblclick(['position' => ['x' => 8, 'y' => 8]]);
    $page->wait(1);

    $page->assertScript("(() => { const input = document.querySelector('{$menu} [data-stage-name-input]'); return input.selectionEnd > input.selectionStart; })()", true)
        ->assertScript('document.querySelectorAll(".ff-column[data-collapsed]").length', 0);

    expect(storedBoardState($user, $entity)?->collapsedColumns ?? [])->toBe([]);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('draws the swatches as rounded tiles on the dropdown surface in light and dark', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $menu = "[data-stage-menu=\"{$ids[1]}\"]";

    $page = openBoard($user, $path);

    $page->page()->locator(boardHeaderTitle($ids[1]))->click();
    $page->page()->locator("{$menu} [data-stage-color-trigger]")->click();
    $page->assertVisible("{$menu} [data-stage-swatches]");

    $geometry = "(() => { const swatch = document.querySelector('{$menu} [data-swatch]'); const box = swatch.getBoundingClientRect(); return [Math.round(box.width), Math.round(box.height), parseFloat(getComputedStyle(swatch).borderTopLeftRadius)]; })()";
    $page->assertScript($geometry, [24, 24, 6]);

    $surface = "(() => { const grid = document.querySelector('{$menu} [data-stage-swatches]'); return getComputedStyle(grid).backgroundColor === getComputedStyle(grid.closest('.fi-dropdown-panel')).backgroundColor; })()";
    $page->assertScript($surface, true);

    $page->script('document.documentElement.classList.add("dark")');
    $page->assertScript($surface, true);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('deletes a stage from its menu after the confirmation shows the card count', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $ids = boardStageOptionIds($model, $fieldCode);
    $column = "[data-column-root=\"{$ids[1]}\"]";

    TenantContextService::setTenantId($workspace->getKey());
    $field = CustomField::query()->forEntity($model)->where('code', $fieldCode)->firstOrFail();
    $model::factory()->recycle([$user, $workspace])->count(2)->create()
        ->each(fn (Task|Opportunity $record) => $record->saveCustomFieldValue($field, $ids[1]));
    TenantContextService::setTenantId(null);

    $page = openBoard($user, $path)->assertVisible($column);

    $page->page()->locator(boardHeaderTitle($ids[1]))->click();
    $page->page()->locator('.fi-dropdown-panel:visible .fi-dropdown-list-item:has-text("Delete ")')->click();

    $page->assertSee('2 '.($entity === CrmEntity::Task ? 'tasks' : 'opportunities').' will have no '.($entity === CrmEntity::Task ? 'status' : 'stage'));
    $page->page()->locator('.fi-modal-window:visible .fi-modal-footer button:has-text("Delete")')->click();

    $page->assertScript(boardWaitFor("document.querySelector('{$column}') === null"), true);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($ids[1]))->toBeNull();
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('adds a stage from the tile after the last column and shows a taken name under the input', function (string $model, string $fieldCode, string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $ids = boardStageOptionIds($model, $fieldCode);
    $taken = CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($ids[0])->name;

    $page = openBoard($user, $path)->assertVisible('.ff-add-column-button');

    $page->page()->locator('.ff-add-column-button')->click();
    $page->page()->locator('.ff-add-column-input')->fill($taken);
    $page->page()->locator('.ff-add-column-input')->press('Enter');
    $page->assertScript(boardWaitFor("document.querySelector('.ff-add-column-error') !== null"), true);

    $page->page()->locator('.ff-add-column-input')->fill('Escalated');
    $page->page()->locator('.ff-add-column-input')->press('Enter');
    $page->assertScript(boardWaitFor("[...document.querySelectorAll('.ff-column')].at(-1)?.textContent.includes('Escalated') === true"), true);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->where('name', 'Escalated')->count())->toBe(1);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');

it('adds a stage in the color picked in its form, focused on open with gray selected', function (string $model, string $fieldCode, string $path): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $blue = OptionColor::Blue->value;

    $page = openBoard($user, $path)->assertVisible('.ff-add-column-button');

    $page->page()->locator('.ff-add-column-button')->click();
    $page->assertScript(boardWaitFor("document.activeElement === document.querySelector('.ff-add-column-input')"), true);

    $page->page()->locator('.ff-add-column-dot')->click();
    $page->assertScript('document.querySelector(\'.ff-add-column-form .ff-swatch[aria-checked="true"]\').getAttribute("aria-label")', 'Set color to '.OptionColor::Gray->label());
    $page->page()->locator('.ff-add-column-form .ff-swatch[aria-label="Set color to '.OptionColor::Blue->label().'"]')->click();
    $page->assertScript("document.querySelector('.ff-add-column-dot').style.getPropertyValue('--color-500')", $blue);

    $page->page()->locator('.ff-add-column-input')->fill('Escalated');
    $page->page()->locator('.ff-add-column-input')->press('Enter');
    $page->assertScript(boardWaitFor("[...document.querySelectorAll('.ff-column')].at(-1)?.textContent.includes('Escalated') === true"), true);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->where('name', 'Escalated')->sole()->settings->color)->toBe($blue);
    $page->assertNoJavaScriptErrors();
})->with('stage order boards');
