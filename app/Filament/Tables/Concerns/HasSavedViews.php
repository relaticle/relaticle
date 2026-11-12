<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\Views\CreateSavedView;
use App\Actions\Views\UpdateSavedView;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Filament\Concerns\ManagesSavedViews;
use App\Models\SavedView;
use Filament\Notifications\Notification;
use Filament\Support\Facades\FilamentView;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Attributes\Url;

trait HasSavedViews
{
    use ManagesSavedViews;

    #[Url(as: 'view')]
    public ?string $requestedSavedViewId = null;

    protected function savedViewEntity(): CrmEntity
    {
        return $this->tableEntity();
    }

    protected function createSavedViewFromCurrentState(string $name): SavedView
    {
        return resolve(CreateSavedView::class)->execute(
            $this->savedViewUser(),
            $this->savedViewWorkspace(),
            $this->tableEntity(),
            $name,
            $this->currentTableState(),
        );
    }

    protected function savedViewCreated(SavedView $view): void
    {
        if (! $this->getActiveSavedView() instanceof SavedView) {
            $this->rememberOwnTableState($this->currentTableState()->withoutWidths());
        }

        $this->activeSavedViewId = $view->getKey();
        $this->markSavedViewApplied();
    }

    /**
     * @return Collection<int, SavedView>
     */
    protected function tableSavedViews(): Collection
    {
        return $this->getVisibleSavedViews()->filter(static fn (SavedView $view): bool => $view->type === SavedViewType::Table)->values();
    }

    protected function getActiveSavedView(): ?SavedView
    {
        return $this->tableSavedViews()->find($this->activeSavedViewId);
    }

    private function redirectToBoardView(SavedView $view): void
    {
        $url = $this->boardViewUrl($view);

        if ($url === null) {
            $this->handleMissingSavedView($view->getKey());

            return;
        }

        $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
    }

    private function saveActiveSavedView(): void
    {
        if ($this->activeSavedViewId === null || $this->tableStateHash === null) {
            return;
        }

        $state = $this->currentTableState();
        $hash = $state->withoutWidths()->hash();

        if ($this->tableStateSignature($state) === $this->tableStateHash || $hash === $this->appliedSavedViewHash) {
            return;
        }

        $view = $this->getActiveSavedView();

        if (! $view instanceof SavedView) {
            $this->handleMissingSavedView($this->activeSavedViewId);

            return;
        }

        try {
            resolve(UpdateSavedView::class)->execute($this->savedViewUser(), $view, ['state' => $state]);
        } catch (ModelNotFoundException $exception) {
            throw_unless($exception->getModel() === SavedView::class, $exception);

            $this->handleMissingSavedView($view->getKey());

            return;
        }

        $this->appliedSavedViewHash = $hash;
        $this->visibleSavedViews = null;
    }

    public function applySavedView(string $viewId): void
    {
        $view = $this->getVisibleSavedViews()->find($viewId);

        if (! $view instanceof SavedView) {
            $this->handleMissingSavedView($viewId);

            return;
        }

        if ($view->type === SavedViewType::Board) {
            $this->redirectToBoardView($view);

            return;
        }

        if (! $this->getActiveSavedView() instanceof SavedView) {
            $this->rememberOwnTableState($this->currentTableState()->withoutWidths());
        }

        $this->applyTableState($view->tableState());
        $this->activeSavedViewId = $view->getKey();
        $this->requestedSavedViewId = null;
        $this->markSavedViewApplied();
    }

    public function applyOwnTable(): void
    {
        $this->requestedSavedViewId = SavedView::BUILT_IN_LIST_LINK;

        if ($this->activeSavedViewId === null) {
            return;
        }

        if (! $this->getActiveSavedView() instanceof SavedView) {
            $this->clearActiveSavedView();

            return;
        }

        $this->applyTableState($this->parkedOwnTable() ?? new TableStateData);
        $this->clearActiveSavedView();
    }

    private function openRequestedSavedView(): void
    {
        $viewId = $this->requestedSavedViewId;
        $this->requestedSavedViewId = null;

        if ($viewId === null || $viewId === $this->activeSavedViewId) {
            return;
        }

        if ($viewId === SavedView::BUILT_IN_LIST_LINK) {
            $this->applyOwnTable();

            return;
        }

        $view = $this->getVisibleSavedViews()->find($viewId);

        if (! $view instanceof SavedView) {
            Notification::make()->warning()->title(__('filament/panel.tables.views.unavailable'))->send();

            return;
        }

        $this->applySavedView($viewId);

        if ($view->type === SavedViewType::Board) {
            return;
        }

        $this->seededFromSavedView = true;
        $this->savesTableStateOnOpen = true;
    }

    protected function clearActiveSavedView(): void
    {
        $this->activeSavedViewId = null;
        $this->appliedSavedViewHash = null;
        $this->rememberOwnTableState(null);
    }
}
