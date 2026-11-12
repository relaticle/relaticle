<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\DeleteSavedView;
use App\Actions\Views\DuplicateSavedView;
use App\Actions\Views\UpdateSavedView;
use App\Enums\CrmEntity;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\VisibleSavedViewsQuery;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

trait ManagesSavedViews
{
    private const array SAVED_VIEW_ROW_ACTIONS = ['renameSavedView', 'deleteSavedView'];

    /** @var Collection<int, SavedView>|null */
    protected ?Collection $visibleSavedViews = null;

    abstract protected function savedViewEntity(): CrmEntity;

    abstract protected function createSavedViewFromCurrentState(string $name): SavedView;

    abstract protected function savedViewCreated(SavedView $view): void;

    abstract protected function clearActiveSavedView(): void;

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
            ->get($this->savedViewUser(), $this->savedViewWorkspace(), $this->savedViewEntity());
    }

    protected function canUpdateSavedView(SavedView $view): bool
    {
        return $this->savedViewUser()->can('update', $view);
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

    public function saveSavedViewAsAction(): Action
    {
        return Action::make('saveSavedViewAs')
            ->label(__('filament/panel.tables.views.save_current'))
            ->modalHeading(__('filament/panel.tables.views.create_heading'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.views.create_submit'))
            ->schema([
                $this->savedViewNameInput(),
            ])
            ->action(function (array $data, Schema $schema): void {
                $view = $this->withViewNameErrors($schema, fn (): SavedView => $this->createSavedViewFromCurrentState($data['name']));

                $this->savedViewCreated($view);
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

    protected function handleMissingSavedView(?string $viewId): void
    {
        $this->forgetSavedView($viewId);

        Notification::make()->warning()->title(__('filament/panel.tables.views.unavailable'))->send();
    }

    protected function forgetSavedView(?string $viewId): void
    {
        if ($this->activeSavedViewId === $viewId) {
            $this->clearActiveSavedView();
        }

        $this->visibleSavedViews = null;
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
