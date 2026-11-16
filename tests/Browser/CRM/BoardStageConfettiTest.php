<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
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

const CONFETTI_PROBE = 'window.confettiProbe = { moves: [], seen: 0, gone: false }; addEventListener("kanban-card-moved", (event) => { window.confettiProbe.moves.push(JSON.parse(JSON.stringify(event.detail))); window.confettiProbe.movedAt = performance.now(); }); new MutationObserver((records) => records.forEach((record) => { record.addedNodes.forEach((node) => node.dataset?.boardConfetti !== undefined && (window.confettiProbe.seen++, window.confettiProbe.seenAt = performance.now())); record.removedNodes.forEach((node) => node.dataset?.boardConfetti !== undefined && (window.confettiProbe.gone = true, window.confettiProbe.goneAt = performance.now())); })).observe(document.body, { childList: true }); true';

const CONFETTI_PAINTED_PER_SIDE = '(() => { const canvas = document.querySelector("canvas[data-board-confetti]"); const pixels = canvas.getContext("2d").getImageData(0, Math.floor(canvas.height * 0.15), canvas.width, Math.floor(canvas.height * 0.35)).data; const painted = [0, 0]; for (let at = 3; at < pixels.length; at += 8) { if (pixels[at] > 0) { painted[((at - 3) / 4) % canvas.width < canvas.width / 2 ? 0 : 1]++; } } return painted; })()';

/**
 * @return array{0: Task|Opportunity, 1: CustomField}
 */
function confettiBoardCard(User $user, string $model, string $fieldCode): array
{
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

    return [$record, $field];
}

function moveCardFromItsMenu(mixed $page, string $card, string $toName): void
{
    $page->page()->locator("{$card} .fi-dropdown-trigger")->click();
    $page->page()->locator(".fi-dropdown-panel[style*='display: block'] .fi-dropdown-list-item:has-text('".__('filament/pages/boards.move_to.label')."')")->click();
    $page->page()->locator(".fi-modal-open [role=radio]:has-text('{$toName}')")->click();
}

/**
 * @return array{0: int, 1: int}
 */
function confettiRisenPerSide(mixed $page): array
{
    for ($attempt = 0; $attempt < 50 && array_sum($page->script(CONFETTI_PAINTED_PER_SIDE)) < 3000; $attempt++) {
        $page->wait(0.1);
    }

    return $page->script(CONFETTI_PAINTED_PER_SIDE);
}

function waitForConfettiToEnd(mixed $page): void
{
    for ($attempt = 0; $attempt < 100 && ! $page->script('window.confettiProbe.gone'); $attempt++) {
        $page->wait(0.1);
    }
}

it('fires confetti for the mover when a card lands in a stage with confetti on, and nowhere else', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    [$record, $field] = confettiBoardCard($user, $model, $fieldCode);
    $ids = boardStageOptionIds($model, $fieldCode);
    $card = "[data-card-id=\"{$record->getKey()}\"]";
    $option = fn (): CustomFieldOption => CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($ids[1]);

    $page = openBoard($user, $path)->assertVisible($card);

    $page->page()->locator(boardHeaderTitle($ids[1]))->click();
    $page->page()->locator("[data-stage-menu=\"{$ids[1]}\"] [data-stage-confetti]")->click();
    waitForPage($page, fn (): bool => $option()->settings->celebrate === true);

    expect($option()->settings->celebrate)->toBeTrue();

    $page->click('.fi-topbar-page-title')
        ->assertScript(boardWaitFor("document.querySelector('[data-board-celebrate]').dataset.boardCelebrate === '[\"{$ids[1]}\"]'"), true)
        ->assertScript(CONFETTI_PROBE, true);

    moveCardFromItsMenu($page, $card, $option()->name);
    $page->assertScript(boardWaitFor('window.confettiProbe.seen === 1'), true)
        ->assertScript('document.querySelectorAll("canvas[data-board-confetti]").length', 1);

    [$left, $right] = confettiRisenPerSide($page);

    expect(min($left, $right) / max($left, $right, 1))->toBeGreaterThan(0.4);

    waitForConfettiToEnd($page);

    $page->assertScript('window.confettiProbe.gone', true)
        ->assertScript('document.querySelectorAll("[data-board-confetti]").length', 0)
        ->assertScript('window.confettiProbe.moves', [[['cardId' => (string) $record->getKey(), 'columnId' => $ids[1], 'position' => $page->script('window.confettiProbe.moves[0][0].position')]]]);

    $page->script('window.confettiProbe.seen = 0; window.confettiProbe.gone = false; true');
    moveCardFromItsMenu($page, $card, CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($ids[0])->name);
    $page->assertScript(boardWaitFor('window.confettiProbe.moves.length === 2'), true)
        ->wait(1)
        ->assertScript('window.confettiProbe.seen', 0)
        ->assertNoJavaScriptErrors();
})->with('stage order boards');

it('fires no confetti when the reader asks for reduced motion', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    [$record] = confettiBoardCard($user, $model, $fieldCode);
    $ids = boardStageOptionIds($model, $fieldCode);
    $card = "[data-card-id=\"{$record->getKey()}\"]";
    $target = CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($ids[1]);
    $target->update(['settings' => ['celebrate' => true]]);

    $page = openBoard($user, $path)->assertVisible($card)->assertScript(CONFETTI_PROBE, true);
    $page->script('(() => { const ask = window.matchMedia.bind(window); window.matchMedia = (query) => query.includes("prefers-reduced-motion") ? { ...ask(query), matches: true } : ask(query); return true; })()');

    moveCardFromItsMenu($page, $card, $target->name);
    $page->assertScript(boardWaitFor('window.confettiProbe.moves.length === 1'), true)
        ->wait(1)
        ->assertScript('window.confettiProbe.seen', 0)
        ->assertNoJavaScriptErrors();
})->with('stage order boards');

it('scatters under a swiping pointer, ends with the canvas removed, and keeps the board clickable meanwhile', function (string $model, string $fieldCode, string $path, CrmEntity $entity): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    [$record] = confettiBoardCard($user, $model, $fieldCode);
    $ids = boardStageOptionIds($model, $fieldCode);
    $card = "[data-card-id=\"{$record->getKey()}\"]";
    $target = CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($ids[1]);
    $target->update(['settings' => ['celebrate' => true]]);

    $page = openBoard($user, $path)->assertVisible($card)->assertScript(CONFETTI_PROBE, true);

    moveCardFromItsMenu($page, $card, $target->name);
    $page->assertScript(boardWaitFor('window.confettiProbe.seen === 1'), true)
        ->assertScript('getComputedStyle(document.querySelector("[data-board-confetti]")).pointerEvents', 'none')
        ->assertScript('(async () => { for (let step = 0; step < 20; step++) { window.dispatchEvent(new PointerEvent("pointermove", { clientX: 100 + step * 60, clientY: 300 + step * 10, pointerType: step % 2 ? "touch" : "mouse" })); await new Promise((resolve) => setTimeout(resolve, 16)); } return true; })()', true);

    $page->page()->locator("{$card} .fi-dropdown-trigger")->click();
    $page->assertVisible(".fi-dropdown-panel[style*='display: block']")
        ->assertScript('document.querySelectorAll("[data-board-confetti]").length <= 1', true);

    waitForConfettiToEnd($page);

    $page->assertScript('window.confettiProbe.gone', true)
        ->assertScript('document.querySelectorAll("[data-board-confetti]").length', 0)
        ->assertNoJavaScriptErrors();
})->with('stage order boards');

it('adds a second win during a burst to the same canvas and keeps it going', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    [$record] = confettiBoardCard($user, Opportunity::class, 'stage');
    $ids = boardStageOptionIds(Opportunity::class, 'stage');
    $card = "[data-card-id=\"{$record->getKey()}\"]";
    $targets = CustomFieldOption::query()->withoutGlobalScopes()->whereIn('id', [$ids[1], $ids[2]])->get()->keyBy('id');
    $targets->each->update(['settings' => ['celebrate' => true]]);

    $page = openBoard($user, 'opportunities/board')->assertVisible($card)->assertScript(CONFETTI_PROBE, true);

    moveCardFromItsMenu($page, $card, $targets[$ids[1]]->name);
    $page->assertScript(boardWaitFor('window.confettiProbe.seen === 1'), true)
        ->assertScript(boardWaitFor('performance.now() - window.confettiProbe.seenAt > 1000'), true);

    moveCardFromItsMenu($page, $card, $targets[$ids[2]]->name);
    $page->assertScript(boardWaitFor('window.confettiProbe.moves.length === 2'), true);

    waitForConfettiToEnd($page);

    expect($page->script('window.confettiProbe.seen'))->toBe(1)
        ->and($page->script('window.confettiProbe.goneAt - window.confettiProbe.movedAt'))->toBeGreaterThan(3600);

    $page->assertNoJavaScriptErrors();
});

it('fires confetti on a drag into a stage with confetti on, flat and with lanes', function (string $model, string $fieldCode, string $path, CrmEntity $entity, string $groupBy): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    [$record] = confettiBoardCard($user, $model, $fieldCode);
    $ids = boardStageOptionIds($model, $fieldCode);
    $card = "[data-card-id=\"{$record->getKey()}\"]";
    CustomFieldOption::query()->withoutGlobalScopes()->whereIn('id', [$ids[1], $ids[0]])->get()->each->update(['settings' => ['celebrate' => true]]);

    $page = openBoard($user, $path)->assertVisible($card)->assertScript(CONFETTI_PROBE, true);

    dragCard($page, $card, "[data-column-root=\"{$ids[1]}\"] .ff-cards");
    $page->assertScript(boardWaitFor('window.confettiProbe.seen === 1'), true);
    waitForConfettiToEnd($page);

    openViewSettingsPanel($page, '[data-board-lanes-trigger]');
    $page->click("[data-board-grouping=\"{$groupBy}\"]")->assertVisible('.ff-lanes-heads');
    closeViewSettings($page);
    $page->script('window.confettiProbe.seen = 0; window.confettiProbe.gone = false; true');

    $lane = $page->script("document.querySelector('{$card}').closest('[data-lane-id]').dataset.laneId");
    dragCard($page, $card, ".ff-cell:has([data-column-id=\"{$ids[0]}\"][data-lane-id=\"{$lane}\"])");
    $page->assertScript(boardWaitFor('window.confettiProbe.seen === 1'), true)
        ->assertNoJavaScriptErrors();
})->with([
    'tasks' => [Task::class, 'status', 'tasks/board', CrmEntity::Task, 'assignee'],
    'opportunities' => [Opportunity::class, 'stage', 'opportunities/board', CrmEntity::Opportunity, 'company'],
]);
