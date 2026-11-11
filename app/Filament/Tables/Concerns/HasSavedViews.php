<?php

declare(strict_types=1);

namespace App\Filament\Tables\Concerns;

use App\Actions\Views\CreateSavedView;
use App\Actions\Views\DeleteSavedView;
use App\Actions\Views\DuplicateSavedView;
use App\Actions\Views\SetDefaultSavedView;
use App\Actions\Views\UpdateSavedView;
use App\Data\TableStateData;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\VisibleSavedViewsQuery;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

trait HasSavedViews
{
    #[Url(as: 'view')]
    public ?string $requestedSavedViewId = null;

    private const array SAVED_VIEW_ROW_ACTIONS = ['renameSavedView', 'deleteSavedView'];

    /** @var Collection<int, SavedView>|null */
    private ?Collection $visibleSavedViews = null;

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        $viewId = $this->vanishedMountedSavedViewId();

        if ($viewId !== null) {
            // Filament drops the submit of an action that turned hidden, which left the modal open on a blank name.
            $this->unmountAction();
            $this->handleMissingSavedView($viewId);

            return null;
        }

        try {
            return parent::callMountedAction($arguments);
        } catch (ModelNotFoundException $exception) {
            throw_unless($exception->getModel() === SavedView::class, $exception);

            $this->unmountAction();
            $this->handleMissingSavedView((string) Arr::first($exception->getIds()));

            return null;
        }
    }

    /**
     * @return Collection<int, SavedView>
     */
    protected function getVisibleSavedViews(): Collection
    {
        return $this->visibleSavedViews ??= resolve(VisibleSavedViewsQuery::class)
            ->get($this->savedViewUser(), $this->savedViewWorkspace(), $this->tableEntity());
    }

    protected function getActiveSavedView(): ?SavedView
    {
        return $this->getVisibleSavedViews()->find($this->activeSavedViewId);
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

    protected function canUpdateSavedView(SavedView $view): bool
    {
        return $this->savedViewUser()->can('update', $view);
    }

    public function applySavedView(string $viewId): void
    {
        $view = $this->getVisibleSavedViews()->find($viewId);

        if (! $view instanceof SavedView) {
            $this->handleMissingSavedView($viewId);

            return;
        }

        if (! $this->getActiveSavedView() instanceof SavedView) {
            $this->rememberOwnTableState($this->currentTableState()->withoutWidths());
        }

        $this->applyTableState($view->state);
        $this->activeSavedViewId = $view->getKey();
        $this->markSavedViewApplied();
    }

    public function duplicateSavedView(string $viewId): void
    {
        $view = $this->getVisibleSavedViews()->find($viewId);

        if (! $view instanceof SavedView) {
            $this->handleMissingSavedView($viewId);

            return;
        }

        try {
            $copy = resolve(DuplicateSavedView::class)->execute($this->savedViewUser(), $view);
        } catch (ValidationException $exception) {
            Notification::make()->warning()->title(Arr::first(Arr::flatten($exception->errors())))->send();

            return;
        }

        $this->visibleSavedViews = null;

        Notification::make()->success()->title(__('filament/panel.tables.views.duplicated', ['name' => e($copy->name)]))->send();
    }

    public function toggleDefaultSavedView(string $viewId): void
    {
        $view = $this->getVisibleSavedViews()->find($viewId);

        if (! $view instanceof SavedView) {
            $this->handleMissingSavedView($viewId);

            return;
        }

        $default = $this->defaultSavedViewId === $view->getKey() ? null : $view;

        resolve(SetDefaultSavedView::class)->execute($this->savedViewUser(), $this->savedViewWorkspace(), $this->tableEntity(), $default);
        $this->defaultSavedViewId = $default?->getKey();
    }

    public function applyOwnTable(): void
    {
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

    public function useStandardTable(): void
    {
        $this->applyTableState(new TableStateData);
        $this->clearActiveSavedView();
    }

    protected function canResetTable(): bool
    {
        return ! $this->getActiveSavedView() instanceof SavedView;
    }

    public function resetTableToDefaultAction(): Action
    {
        return Action::make('resetTableToDefault')
            ->label(__('filament/panel.tables.views.reset'))
            ->requiresConfirmation()
            ->modalHeading(__('filament/panel.tables.views.reset_heading'))
            ->modalDescription(__('filament/panel.tables.views.reset_description'))
            ->modalSubmitActionLabel(__('filament/panel.tables.views.reset_confirm'))
            ->visible(fn (): bool => $this->canResetTable())
            ->action(fn () => $this->useStandardTable());
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

        if (! $this->getVisibleSavedViews()->contains('id', $viewId)) {
            Notification::make()->warning()->title(__('filament/panel.tables.views.unavailable'))->send();

            return;
        }

        $this->applySavedView($viewId);
        $this->seededFromSavedView = true;
        $this->savesTableStateOnOpen = true;
    }

    public function saveSavedViewAsAction(): Action
    {
        return Action::make('saveSavedViewAs')
            ->label(__('filament/panel.tables.views.save_current'))
            ->modalHeading(__('filament/panel.tables.views.create_heading'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.views.create_submit'))
            ->schema([
                $this->savedViewNameInput(),
                Text::make(__('filament/panel.tables.views.create_note')),
            ])
            ->action(function (array $data, Schema $schema): void {
                $view = $this->withViewNameErrors($schema, fn (): SavedView => resolve(CreateSavedView::class)->execute(
                    $this->savedViewUser(),
                    $this->savedViewWorkspace(),
                    $this->tableEntity(),
                    $data['name'],
                    $this->currentTableState(),
                ));

                $this->activeSavedViewId = $view->getKey();
                $this->markSavedViewApplied();
                $this->visibleSavedViews = null;

                Notification::make()->success()->title(__('filament/panel.tables.views.created', ['name' => e($view->name)]))->send();
            });
    }

    public function renameSavedViewAction(): Action
    {
        return Action::make('renameSavedView')
            ->label(__('filament/panel.tables.views.rename'))
            ->modalHeading(__('filament/panel.tables.views.rename'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.views.save'))
            ->visible(fn (array $arguments): bool => $this->canUpdateArgumentSavedView($arguments))
            ->fillForm(fn (array $arguments): array => ['name' => $this->argumentSavedView($arguments)?->name])
            ->schema([$this->savedViewNameInput()])
            ->action(function (array $arguments, array $data, Schema $schema): void {
                $view = $this->requireArgumentSavedView($arguments);

                $view = $this->withViewNameErrors($schema, fn (): SavedView => resolve(UpdateSavedView::class)->execute(
                    $this->savedViewUser(),
                    $view,
                    ['name' => $data['name']],
                ));

                $this->visibleSavedViews = null;

                Notification::make()->success()->title(__('filament/panel.tables.views.renamed', ['name' => e($view->name)]))->send();
            });
    }

    public function deleteSavedViewAction(): Action
    {
        return Action::make('deleteSavedView')
            ->label(__('filament/panel.tables.views.delete'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments): string => __('filament/panel.tables.views.delete_heading', ['name' => $this->argumentSavedView($arguments)?->name]))
            ->modalDescription(__('filament/panel.tables.views.delete_description'))
            ->visible(fn (array $arguments): bool => $this->canUpdateArgumentSavedView($arguments))
            ->action(function (array $arguments): void {
                $view = $this->requireArgumentSavedView($arguments);

                if (! resolve(DeleteSavedView::class)->execute($this->savedViewUser(), $view)) {
                    $this->handleMissingSavedView($view->getKey());

                    return;
                }

                $this->forgetSavedView($view->getKey());

                Notification::make()->success()->title(__('filament/panel.tables.views.deleted', ['name' => e($view->name)]))->send();
            });
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function argumentSavedView(array $arguments): ?SavedView
    {
        $viewId = $arguments['view'] ?? null;

        return is_string($viewId) ? $this->getVisibleSavedViews()->find($viewId) : null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requireArgumentSavedView(array $arguments): SavedView
    {
        return $this->argumentSavedView($arguments) ?? abort(404);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function canUpdateArgumentSavedView(array $arguments): bool
    {
        $view = $this->argumentSavedView($arguments);

        return $view instanceof SavedView && $this->canUpdateSavedView($view);
    }

    private function vanishedMountedSavedViewId(): ?string
    {
        $action = $this->getMountedAction();
        $viewId = $action?->getArguments()['view'] ?? null;

        if (! in_array($action?->getName(), self::SAVED_VIEW_ROW_ACTIONS, true) || ! is_string($viewId)) {
            return null;
        }

        return $this->getVisibleSavedViews()->find($viewId) instanceof SavedView ? null : $viewId;
    }

    private function handleMissingSavedView(?string $viewId): void
    {
        $this->forgetSavedView($viewId);

        Notification::make()->warning()->title(__('filament/panel.tables.views.unavailable'))->send();
    }

    private function forgetSavedView(?string $viewId): void
    {
        if ($this->activeSavedViewId === $viewId) {
            $this->clearActiveSavedView();
        }

        if ($this->defaultSavedViewId === $viewId) {
            $this->defaultSavedViewId = null;
        }

        $this->visibleSavedViews = null;
    }

    private function clearActiveSavedView(): void
    {
        $this->activeSavedViewId = null;
        $this->appliedSavedViewHash = null;
        $this->rememberOwnTableState(null);
    }

    private function savedViewNameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('filament/panel.tables.views.name'))
            ->required()
            ->maxLength(SavedView::NAME_MAX_LENGTH)
            ->autofocus();
    }

    /**
     * @param  Closure(): SavedView  $write
     */
    private function withViewNameErrors(Schema $schema, Closure $write): SavedView
    {
        try {
            return $write();
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                "{$schema->getStatePath()}.name" => $exception->errors()['name'] ?? [],
            ]);
        }
    }

    private function savedViewUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function savedViewWorkspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }
}
