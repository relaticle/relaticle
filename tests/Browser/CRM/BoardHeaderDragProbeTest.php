<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Enums\CustomFields\TaskField;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Relaticle\CustomFields\Services\TenantContextService;

it('probes the third header drag after a card was opened', function (): void {
    $this->withVite();
    $model = Task::class;
    $fieldCode = TaskField::STATUS->value;
    $entity = CrmEntity::Task;

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
    $state = '(() => ({ dom: [...document.querySelectorAll(".ff-columns > .ff-column")].map((c) => c.dataset.columnRoot), drag: window.boardDrag, pending: window.boardDragRequests?.pending, modals: [...document.querySelectorAll(".fi-modal-window")].filter((m) => m.checkVisibility()).length, openModals: document.querySelectorAll(".fi-modal.fi-modal-open").length, sortable: !! window.Sortable.get(document.querySelector(".ff-columns")), activeTag: document.activeElement?.tagName, overlay: document.elementFromPoint(innerWidth / 2, 200)?.className?.toString().slice(0, 80) }))()';

    $page = openBoard($user, 'tasks/board')->assertVisible($card);

    dragWithPointerEvents($page, boardHeaderTitle($ids[0]), boardHeaderTitle($ids[1]));
    waitForStoredStageOrder($page, $user, $entity, $first);
    dragCard($page, $card, "[data-column-root=\"{$ids[1]}\"] .ff-cards");
    $page->wait(1.5);
    dragWithPointerEvents($page, boardHeaderTitle($ids[2]), boardHeaderTitle($ids[1]));
    waitForStoredStageOrder($page, $user, $entity, $second);

    $page->click($card)->assertVisible('.fi-modal-window')->wait(0.5);
    $page->script('document.querySelector(".fi-modal-window .fi-modal-close-btn")?.click()');
    $page->wait(1);
    $beforeThird = $page->script($state);

    dragWithPointerEvents($page, boardHeaderTitle($ids[0]), boardHeaderTitle($ids[2]));
    waitForStoredStageOrder($page, $user, $entity, $third);

    if (storedStageOrder($user, $entity) !== $third) {
        $this->fail(json_encode(['ids' => $ids, 'expected' => $third, 'stored' => storedStageOrder($user, $entity), 'before_third' => $beforeThird, 'after_third' => $page->script($state), 'after_more_2s' => $page->wait(2)->script($state), 'stored_after_more' => storedStageOrder($user, $entity)], JSON_PRETTY_PRINT));
    }

    expect(true)->toBeTrue();
});
