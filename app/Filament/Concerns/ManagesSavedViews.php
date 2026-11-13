<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\DeleteSavedView;
use App\Actions\Views\DuplicateSavedView;
use App\Actions\Views\UpdateSavedView;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Views\VisibleSavedViewsQuery;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Enums\GridDirection;
use Filament\Support\Facades\FilamentView;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Relaticle\Flowforge\Contracts\HasBoard;

use function Filament\Support\generate_icon_html;

trait ManagesSavedViews
{
    private const array SAVED_VIEW_ROW_ACTIONS = ['renameSavedView', 'deleteSavedView'];

    /** @var Collection<int, SavedView>|null */
    protected ?Collection $visibleSavedViews = null;

    abstract protected function savedViewEntity(): CrmEntity;

    abstract protected function createSavedViewFromCurrentState(string $name, SavedViewType $type): SavedView;

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
            ->modalWidth('3xl')
            ->modalSubmitActionLabel(__('filament/panel.tables.views.create_submit'))
            ->schema([
                $this->savedViewTypeInput(),
                $this->savedViewNameInput(),
            ])
            ->action(function (array $data, Schema $schema): void {
                $type = $this->chosenSavedViewType($data);
                $view = $this->withViewNameErrors($schema, fn (): SavedView => $this->createSavedViewFromCurrentState($data['name'], $type));
                $opensHere = $type === $this->pageSavedViewType();

                if ($opensHere) {
                    $this->savedViewCreated($view);
                }

                $this->visibleSavedViews = null;

                Notification::make()->success()->title(__('filament/panel.tables.views.created', ['name' => e($view->name)]))->send();

                if (! $opensHere) {
                    $url = $this->savedViewPageUrl($view);

                    $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
                }
            });
    }

    public function renameSavedViewAction(): Action
    {
        return Action::make('renameSavedView')
            ->label(__('filament/panel.tables.views.rename'))
            ->modalHeading(__('filament/panel.tables.views.rename'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament/panel.tables.views.save'))
            ->visible(fn (array $arguments): bool => $this->argumentSavedView($arguments) instanceof SavedView)
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
            ->visible(fn (array $arguments): bool => $this->argumentSavedView($arguments) instanceof SavedView)
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

    private function savedViewTypeInput(): Radio
    {
        $types = SavedViewType::cases();

        return Radio::make('type')
            ->label(__('filament/panel.tables.views.type'))
            ->options(fn (): array => collect($types)->mapWithKeys(fn (SavedViewType $type): array => [$type->value => $this->savedViewTypeLabel($type)])->all())
            ->descriptions(collect($types)->mapWithKeys(fn (SavedViewType $type): array => [$type->value => $type->getDescription()])->all())
            ->default($this->pageSavedViewType()->value)
            ->required()
            ->markAsRequired(false)
            ->visible(fn (): bool => $this->offersSavedViewType())
            ->columns(['default' => 1, 'sm' => 2])
            ->gridDirection(GridDirection::Row)
            ->extraAttributes(['class' => 'fi-view-type-radio']);
    }

    private function savedViewTypeLabel(SavedViewType $type): HtmlString
    {
        $icon = generate_icon_html($type->getIcon())?->toHtml();

        return new HtmlString('<span class="fi-view-type-tile" aria-hidden="true">'.$icon.'</span><span class="fi-view-type-name">'.e($type->getLabel()).'</span>');
    }

    private function offersSavedViewType(): bool
    {
        $pages = $this->viewSwitcherPages();

        return isset($pages['list'], $pages['board']);
    }

    private function pageSavedViewType(): SavedViewType
    {
        return $this instanceof HasBoard ? SavedViewType::Board : SavedViewType::Table;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function chosenSavedViewType(array $data): SavedViewType
    {
        return $this->offersSavedViewType() ? SavedViewType::from($data['type']) : $this->pageSavedViewType();
    }

    private function savedViewNameInput(): TextInput
    {
        return TextInput::make('name')
            ->label(__('filament/panel.tables.views.name'))
            ->placeholder(__('filament/panel.tables.views.name_placeholder'))
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
