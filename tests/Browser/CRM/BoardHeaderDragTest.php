<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\CustomField;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TasksBoard::class, OpportunitiesBoard::class, MoveCardAction::class);
pest()->group('webkit');

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
