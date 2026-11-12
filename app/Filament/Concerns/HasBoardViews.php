<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\CreateSavedView;
use App\Actions\Views\SaveBoardState;
use App\Data\BoardStateData;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\Concerns\FiltersWithRules;
use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\SortList;
use App\Models\CustomField;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\Sorts\CustomFieldSort;
use App\Queries\Views\StoredTablePreferenceQuery;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Facades\FilamentView;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Relaticle\Flowforge\Exceptions\CardMoveRejectedException;

trait HasBoardViews
{
    use FiltersWithRules;
    use ManagesSavedViews;

    private const array GROUPING_FIELDS = ['company' => 'company', 'assignee' => 'assignees'];

    #[Url(as: 'sort')]
    public ?string $boardSort = null;

    #[Url(as: 'view')]
    public ?string $activeSavedViewId = null;

    #[Locked]
    public ?string $boardStateHash = null;

    private ?BoardStateData $storedBoardState = null;

    private ?CustomField $resolvedBoardColumnField = null;

    public function mountHasBoardViews(): void
    {
        $this->tableSearch = '';

        $requested = $this->activeSavedViewId;
        $this->activeSavedViewId = null;

        if ($requested !== null && $this->openRequestedBoardView($requested)) {
            return;
        }

        $filters = $this->boardFilters($this->tableFilters);
        $grouping = $this->knownGrouping($this->groupBy);
        $sort = $this->offeredSort($this->boardSort);

        if ($filters['conditions']['rules'] === [] && $grouping === null && $sort === null) {
            $this->applyBoardState($this->storedBoardState());

            return;
        }

        $this->tableFilters = $filters['conditions']['rules'] === [] ? null : $filters;
        $this->groupBy = $grouping;
        $this->boardSort = $sort;
        $this->boardStateHash = $this->boardStateSignature($this->currentBoardState());
    }

    public function hydrateHasBoardViews(): void
    {
        if ($this->activeSavedViewId !== null && ! $this->activeBoardView() instanceof SavedView) {
            $this->handleMissingSavedView($this->activeSavedViewId);
        }
    }

    public function updatedActiveSavedViewId(): void
    {
        $viewId = $this->activeSavedViewId;
        $this->activeSavedViewId = null;

        $viewId === null ? $this->applyOwnBoard() : $this->applySavedView($viewId);
    }

    public function applySavedView(string $viewId): void
    {
        $view = $this->getVisibleSavedViews()->find($viewId);

        if (! $view instanceof SavedView) {
            $this->handleMissingSavedView($viewId);

            return;
        }

        if ($view->type === SavedViewType::Table) {
            $this->redirectToListView($view);

            return;
        }

        $this->openBoardView($view);
        $this->rebuildBoard();
    }

    public function applyOwnBoard(): void
    {
        $this->activeSavedViewId = null;
        $this->forgetStoredBoardState();
        $this->applyBoardState($this->storedBoardState());
        $this->rebuildBoard();
    }

    protected function activeBoardView(): ?SavedView
    {
        if ($this->activeSavedViewId === null) {
            return null;
        }

        $view = $this->getVisibleSavedViews()->find($this->activeSavedViewId);

        return $view instanceof SavedView && $view->type === SavedViewType::Board ? $view : null;
    }

    protected function savedViewEntity(): CrmEntity
    {
        return $this->filterBarEntity();
    }

    protected function createSavedViewFromCurrentState(string $name, SavedViewType $type): SavedView
    {
        return resolve(CreateSavedView::class)->execute(
            $this->viewSwitcherUser(),
            $this->viewSwitcherWorkspace(),
            $this->filterBarEntity(),
            $name,
            $type === SavedViewType::Board
                ? $this->currentBoardState()
                : new TableStateData(filters: $this->canonicalTableFilters($this->tableFilters)),
        );
    }

    protected function savedViewCreated(SavedView $view): void
    {
        $this->visibleSavedViews = null;
        $this->resolvedBoardColumnField = null;
        $this->activeSavedViewId = $view->getKey();
        $this->boardStateHash = $this->boardStateSignature($this->currentBoardState());
    }

    protected function clearActiveSavedView(): void
    {
        $this->activeSavedViewId = null;
        $this->visibleSavedViews = null;
        $this->forgetStoredBoardState();
        $this->boardStateHash = $this->boardStateSignature($this->currentBoardState());
        $this->rebuildBoard();
    }

    private function openRequestedBoardView(string $viewId): bool
    {
        if (in_array($viewId, [SavedView::BUILT_IN_LIST_LINK, 'board', 'cards'], true)) {
            return false;
        }

        $view = $this->getVisibleSavedViews()->find($viewId);

        if (! $view instanceof SavedView) {
            $this->handleMissingSavedView($viewId);

            return false;
        }

        if ($view->type === SavedViewType::Table) {
            $this->redirectToListView($view);

            return true;
        }

        $this->openBoardView($view);

        return true;
    }

    private function openBoardView(SavedView $view): void
    {
        $this->resolvedBoardColumnField = null;
        $this->activeSavedViewId = $view->getKey();
        $this->applyBoardState($view->boardState());
    }

    private function redirectToListView(SavedView $view): void
    {
        $url = $this->savedViewPageUrl($view);

        $this->redirect($url, navigate: FilamentView::hasSpaMode($url));
    }

    public function dehydrateHasBoardViews(): void
    {
        if ($this->boardStateHash === null) {
            return;
        }

        $state = $this->currentBoardState();
        $hash = $this->boardStateSignature($state);

        if ($hash === $this->boardStateHash) {
            return;
        }

        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SaveBoardState::class)->execute(
            $this->viewSwitcherUser(),
            $this->viewSwitcherWorkspace(),
            $this->filterBarEntity(),
            ['filters' => $state->filters, 'sort' => $state->sort, 'grouping' => $state->grouping],
            $view,
        ));

        if (! $saved instanceof BoardStateData) {
            return;
        }

        $this->boardSort = $state->sort;
        $this->groupBy = $state->grouping;
        $this->forgetStoredBoardState();
        $this->boardStateHash = $hash;
    }

    /**
     * @param  Closure(?SavedView): BoardStateData  $write
     */
    protected function writingBoardState(Closure $write): ?BoardStateData
    {
        try {
            return $write($this->activeBoardView());
        } catch (ModelNotFoundException $exception) {
            throw_unless($exception->getModel() === SavedView::class, $exception);

            $this->handleMissingSavedView($this->activeSavedViewId);

            return null;
        }
    }

    protected function storedBoardState(): BoardStateData
    {
        $view = $this->activeBoardView();

        if ($view instanceof SavedView) {
            return $view->boardState();
        }

        if (! $this->storedBoardState instanceof BoardStateData) {
            $preference = resolve(StoredTablePreferenceQuery::class)
                ->get($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->filterBarEntity());

            $this->storedBoardState = $preference instanceof TablePreference ? $preference->board_state : new BoardStateData;
        }

        return $this->storedBoardState;
    }

    protected function forgetStoredBoardState(): void
    {
        $this->storedBoardState = null;
        $this->resolvedBoardColumnField = null;
        $this->visibleSavedViews = null;
    }

    protected function currentBoardState(): BoardStateData
    {
        return BoardStateData::from([
            ...$this->storedBoardState()->toArray(),
            'filters' => $this->boardFilters($this->tableFilters),
            'sort' => $this->offeredSort($this->boardSort),
            'grouping' => $this->knownGrouping($this->groupBy),
        ]);
    }

    protected function applyBoardState(BoardStateData $state): void
    {
        $this->tableFilters = $state->filters === [] ? null : $this->boardFilters($state->filters);
        $this->boardSort = $this->offeredSort($state->sort);
        $this->groupBy = $this->knownGrouping($state->grouping);
        $this->updatedGroupBy();
        $this->boardStateHash = $this->boardStateSignature($this->currentBoardState());
    }

    private function boardStateSignature(BoardStateData $state): string
    {
        return new BoardStateData(filters: $state->filters, grouping: $state->grouping, sort: $state->sort)->hash();
    }

    private function offeredSort(?string $sort): ?string
    {
        return filled($sort) ? SortList::format(array_intersect_key(SortList::parse($sort), $this->boardSortableFields())) : null;
    }

    private function knownGrouping(?string $grouping): ?string
    {
        return $grouping !== null && array_key_exists($grouping, $this->getBoard()->getGroupings()) ? $grouping : null;
    }

    protected function filterBarEntity(): CrmEntity
    {
        return $this->viewSwitcherEntity();
    }

    protected function filterBarViewKey(): ?string
    {
        return $this->activeBoardView()?->getKey();
    }

    /**
     * @param  array<string, FilterField>  $catalog
     * @return array{sorts: list<array{column: string, direction: string, label: string}>, sortable: list<array{key: string, label: string, icon: string}>, groups: list<array{id: string, label: string, icon: ?string}>, grouping: ?array{id: string, direction: string, label: string}}
     */
    protected function filterBarOrdering(array $catalog): array
    {
        $fields = $this->boardSortableFields();

        return [
            'sorts' => array_map(
                fn (string $column, string $direction): array => ['column' => $column, 'direction' => $direction, 'label' => $fields[$column]['label']],
                array_keys($this->activeBoardSorts()),
                $this->activeBoardSorts(),
            ),
            'sortable' => $this->lanesShown() ? [] : array_map(
                fn (string $key, array $field): array => ['key' => $key, ...$field],
                array_keys($fields),
                $fields,
            ),
            'groups' => [],
            'grouping' => null,
        ];
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    protected function boardSortableFields(): array
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $entity = $this->filterBarEntity();
        $catalog = $this->filterFieldCatalog();
        $codes = resolve(CustomFieldFilterSchema::class)->sortableFields($user, $entity->value)->keys()->all();
        $fields = [];

        foreach ([...$entity->query()::sorts(), ...array_map(fn (string $code): string => FilterFieldCatalog::CUSTOM_FIELD_PREFIX.$code, $codes)] as $key) {
            if (isset($catalog[$key])) {
                $fields[$key] = ['label' => $catalog[$key]->label, 'icon' => $this->filterFieldIconPath($catalog[$key]->icon)];
            }
        }

        return $fields;
    }

    /**
     * @return array<string, 'asc'|'desc'>
     */
    private function activeBoardSorts(): array
    {
        return $this->lanesShown() ? [] : array_intersect_key(SortList::parse($this->boardSort), $this->boardSortableFields());
    }

    protected function lanesShown(): bool
    {
        return $this->getBoard()->getActiveGrouping() !== null;
    }

    public function setFilterBarSorts(mixed $sorts = []): void
    {
        $this->boardSort = SortList::format(SortList::fromClient($sorts, $this->boardSortableFields()));
        $this->rebuildBoard();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function sortBoardQuery(Builder $query): Builder
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return $query;
        }

        $customFields = resolve(CustomFieldFilterSchema::class)->sortableFields($user, $this->filterBarEntity()->value);

        foreach ($this->activeBoardSorts() as $key => $direction) {
            $field = $customFields->get(Str::after($key, FilterFieldCatalog::CUSTOM_FIELD_PREFIX));

            if (str_starts_with($key, FilterFieldCatalog::CUSTOM_FIELD_PREFIX) && $field !== null) {
                new CustomFieldSort($field)($query, $direction === 'desc', $key);
            } else {
                $query->orderBy($query->getModel()->qualifyColumn($key), $direction);
            }
        }

        return $query;
    }

    /**
     * @return Builder<Model>|null
     */
    #[Unexposed]
    public function getFilteredTableQuery(): ?Builder
    {
        $query = parent::getFilteredTableQuery();

        return $query instanceof Builder ? $this->sortBoardQuery(clone $query) : $query;
    }

    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null,
        ?string $fromLaneId = null,
        ?string $toLaneId = null,
    ): bool {
        if ($this->activeBoardSorts() !== []) {
            $afterCardId = null;
            $beforeCardId = $this->topCardKey($targetColumnId, $cardId);
        }

        return parent::moveCard($cardId, $targetColumnId, $afterCardId, $beforeCardId, $fromLaneId, $toLaneId);
    }

    private function topCardKey(string $columnId, string $exceptCardId): ?string
    {
        $board = $this->getBoard();
        $position = $board->getPositionIdentifierAttribute();
        $card = (clone $board->getQuery())
            ->where($board->getColumnIdentifierAttribute(), $columnId)
            ->whereKeyNot($exceptCardId)
            ->whereNotNull($position)
            ->orderBy($position)
            ->orderBy($board->getQuery()->getModel()->getKeyName())
            ->first();

        return $card?->getKey();
    }

    protected function refuseReorderWhileSorted(Model $card, string $targetColumnId): void
    {
        $sorts = $this->activeBoardSorts();

        if ($sorts === [] || $this->getBoard()->getRecordColumnId($card) !== $targetColumnId) {
            return;
        }

        $fields = $this->boardSortableFields();

        throw CardMoveRejectedException::because(__('filament/pages/boards.sorted_no_reorder', [
            'field' => implode(', ', array_map(fn (string $column): string => $fields[$column]['label'], array_keys($sorts))),
        ]));
    }

    public function setBoardGrouping(mixed $group = null): void
    {
        if ($group !== null && (! is_string($group) || ! array_key_exists($group, $this->getBoard()->getGroupings()))) {
            return;
        }

        $this->groupBy = $group;
        $this->updatedGroupBy();
        $this->rebuildBoard();
    }

    /**
     * @return list<array{id: string, label: string, icon: ?string}>
     */
    protected function boardGroupings(): array
    {
        $catalog = $this->filterFieldCatalog();
        $groupings = [];

        foreach ($this->getBoard()->getGroupings() as $name => $definition) {
            $groupings[] = [
                'id' => $name,
                'label' => ColumnHeaderLabel::text($definition->getLabel()),
                'icon' => $this->orderingIcon($catalog, [self::GROUPING_FIELDS[$name] ?? $name]),
            ];
        }

        return $groupings;
    }

    protected function boardStateWritten(BoardStateData $state): void
    {
        $this->forgetStoredBoardState();

        if (! $this->activeBoardView() instanceof SavedView) {
            $this->storedBoardState = $state;
        }

        $this->columnCardLimits = [];
        $this->cellCardLimits = [];
        $this->rebuildBoard();
    }

    protected function rebuildBoard(): void
    {
        $this->board = $this->board($this->makeBoard());
        $this->table = $this->table($this->makeTable());
    }
}
