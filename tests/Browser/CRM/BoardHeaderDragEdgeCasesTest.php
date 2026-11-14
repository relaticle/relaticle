<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;

mutates(TasksBoard::class, OpportunitiesBoard::class, MoveCardAction::class);
pest()->group('webkit');

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

    $page->assertScript(boardWaitFor('[...document.querySelectorAll(".fi-no-notification")].some((notification) => notification.textContent.includes('.json_encode(__('filament/pages/boards.stage_order_failed')).'))'), true)
        ->assertScript(BOARD_COLUMN_IDS, $ids);

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
