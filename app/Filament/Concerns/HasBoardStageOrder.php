<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\CustomFields\SetCustomFieldOptions;
use App\Actions\Views\ExpandBoardColumns;
use App\Actions\Views\MoveBoardCard;
use App\Actions\Views\PlaceBoardCard;
use App\Actions\Views\ReorderBoardStages;
use App\Actions\Views\SaveBoardState;
use App\Actions\Views\SetBoardColumnCollapsed;
use App\Actions\Views\SetBoardColumnField;
use App\Actions\Views\SetBoardColumnVisibility;
use App\Data\BoardStateData;
use App\Enums\CustomFieldType;
use App\Enums\OptionColor;
use App\Enums\WorkspaceCapability;
use App\Exceptions\OptionsChangedException;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\SavedView;
use App\Models\Workspace;
use App\Queries\Views\BoardColumnFieldsQuery;
use App\Support\CustomFieldOptionPlan;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use LogicException;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\Flowforge\Column;
use Relaticle\Flowforge\Exceptions\CardMoveRejectedException;
use Relaticle\Flowforge\Support\ColorResolver;

trait HasBoardStageOrder
{
    /** @var Collection<string, CustomField>|null */
    private ?Collection $boardColumnFieldList = null;

    /**
     * @param  list<mixed>  $optionIds
     */
    public function reorderBoardStages(array $optionIds): void
    {
        $columnIds = $this->boardColumnOptions()->pluck('id')->all();
        $known = array_values(array_intersect(array_filter($optionIds, is_string(...)), $columnIds));

        if ($known === []) {
            return;
        }

        $fieldCode = $this->boardColumnField()->code;
        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(ReorderBoardStages::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $known, $view, $fieldCode));

        if (! $saved instanceof BoardStateData) {
            return;
        }

        $this->boardStateWritten($saved);
    }

    public function setBoardColumnVisible(mixed $optionId = null, mixed $visible = true): void
    {
        $visible = filter_var($visible, FILTER_VALIDATE_BOOL);
        $visibleIds = $this->visibleBoardColumnIds();

        if (! is_string($optionId) || ! $this->canSetColumnVisible($optionId, $visible, $visibleIds)) {
            return;
        }

        $fieldCode = $this->boardColumnField()->code;
        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SetBoardColumnVisibility::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $fieldCode, $optionId, $visible, $view));

        if (! $saved instanceof BoardStateData) {
            return;
        }

        $this->boardStateWritten($saved);
    }

    /**
     * @param  list<string>  $visibleIds
     */
    private function canSetColumnVisible(string $optionId, bool $visible, array $visibleIds): bool
    {
        if ($optionId === BoardStateData::NO_VALUE_COLUMN) {
            return true;
        }

        return $this->boardColumnOptions()->contains('id', $optionId) && ($visible || $visibleIds !== [$optionId]);
    }

    public function setBoardColumnField(mixed $code = null): void
    {
        if ($code !== null && (! is_string($code) || ! $this->boardColumnFields()->has($code))) {
            return;
        }

        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SetBoardColumnField::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $code, $view));

        if (! $saved instanceof BoardStateData) {
            return;
        }

        $this->boardStateWritten($saved);
    }

    public function createColumnAttributeAction(): Action
    {
        return $this->attributeAction('createColumnAttribute', CustomFieldType::SELECT, function (CustomField $field): void {
            $this->boardColumnFieldList = null;
            $this->setBoardColumnField($field->code);
            $this->setBoardColumnVisible(BoardStateData::NO_VALUE_COLUMN, true);

            Notification::make()->success()->title(__('filament/pages/boards.view_settings.columns_grouped_by_created', ['name' => e($field->name)]))->send();
        });
    }

    public function setBoardColumnCollapsed(mixed $optionId = null, mixed $collapsed = true): void
    {
        $collapsed = filter_var($collapsed, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if (! is_string($optionId) || $collapsed === null || ! $this->ownsCollapsibleColumn($optionId)) {
            return;
        }

        $fieldCode = $this->boardColumnField()->code;
        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SetBoardColumnCollapsed::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $fieldCode, $optionId, $collapsed, $view));

        if ($saved instanceof BoardStateData) {
            $this->boardStateWritten($saved);
        }
    }

    public function setBoardColumnsCollapsed(mixed $collapsed = false): void
    {
        $collapsed = filter_var($collapsed, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($collapsed === null || ($collapsed === $this->storedBoardState()->columnsCollapsed && ($collapsed || $this->collapsedBoardColumnIds() === []))) {
            return;
        }

        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => $collapsed
            ? resolve(SaveBoardState::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), ['columns_collapsed' => true], $view)
            : resolve(ExpandBoardColumns::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $this->boardColumnField()->code, $view));

        if ($saved instanceof BoardStateData) {
            $this->boardStateWritten($saved);
        }
    }

    /**
     * @return list<string>
     */
    protected function collapsedBoardColumnIds(): array
    {
        $state = $this->storedBoardState();
        $collapsed = array_values(array_intersect($state->collapsedColumns, $this->boardColumnOptions()->pluck('id')->all()));

        return $this->showsNoValueColumn() && in_array($this->boardColumnField()->code, $state->collapsedNoValueColumns, true)
            ? [BoardStateData::NO_VALUE_COLUMN, ...$collapsed]
            : $collapsed;
    }

    private function ownsCollapsibleColumn(string $optionId): bool
    {
        return $optionId === BoardStateData::NO_VALUE_COLUMN
            ? $this->showsNoValueColumn()
            : $this->boardColumnOptions()->contains('id', $optionId);
    }

    public function renameBoardColumn(mixed $optionId = null, mixed $name = null, mixed $was = null): void
    {
        $name = is_string($name) ? trim($name) : '';

        $this->writeStageOption($optionId, ['name' => $name, ...(is_string($was) ? ['was' => $was] : [])], fn (array $option): bool => $option['name'] === $name);
    }

    public function setBoardColumnColor(mixed $optionId = null, mixed $color = null): void
    {
        if (! is_string($color) || $color === '') {
            return;
        }

        $colorsOn = $this->boardColumnField()->settings->enable_option_colors ?? false;

        $this->writeStageOption($optionId, ['color' => $color], fn (array $option): bool => $colorsOn && strtolower((string) $option['stored']) === strtolower($color));
    }

    public function setBoardColumnCelebrate(mixed $optionId = null, mixed $on = false): void
    {
        $on = filter_var($on, FILTER_VALIDATE_BOOLEAN);

        $this->writeStageOption($optionId, ['celebrate' => $on], fn (array $option): bool => $option['celebrate'] === $on);
    }

    /**
     * @return list<string>
     */
    public function celebratingBoardColumnIds(): array
    {
        return array_values($this->boardColumnOptions()->where('celebrate', true)->pluck('id')->all());
    }

    /**
     * @param  array{name?: string, was?: string, color?: string, celebrate?: bool}  $change
     * @param  Closure(array{id: string, name: string, color: string, stored: ?string, celebrate: bool}): bool  $unchanged
     */
    private function writeStageOption(mixed $optionId, array $change, Closure $unchanged): void
    {
        $option = is_string($optionId) ? $this->boardColumnOptions()->firstWhere('id', $optionId) : null;

        if ($option === null) {
            return;
        }

        $this->resetErrorBag("boardStageMenu.{$option['id']}");

        if ($unchanged($option)) {
            return;
        }

        $field = $this->boardColumnField();
        $options = array_map(
            static fn (array $entry): array => $entry['id'] === $option['id'] ? [...$entry, ...$change] : $entry,
            $this->stageOptionEntries($field),
        );

        try {
            resolve(SetCustomFieldOptions::class)->execute($this->viewSwitcherUser(), $field, ['options' => $options, 'removed' => []]);
        } catch (ValidationException $exception) {
            $this->addError("boardStageMenu.{$option['id']}", $this->stageWriteFailure($exception, $change['name'] ?? null));

            return;
        }

        $this->refreshBoardAfterStageWrite();
    }

    private function refreshBoardAfterStageWrite(): void
    {
        $this->boardColumnFieldList = null;
        $this->forgetStoredBoardState();
        $this->rebuildBoard();
    }

    /**
     * @return list<array{id: string, name: string, was: string}>
     */
    private function stageOptionEntries(CustomField $field): array
    {
        return array_values($field->options->map(static fn (CustomFieldOption $stage): array => [
            'id' => (string) $stage->getKey(),
            'name' => (string) $stage->getAttribute('name'),
            'was' => (string) $stage->getAttribute('name'),
        ])->all());
    }

    /**
     * @param  array<string, mixed>  $column
     */
    protected function boardStageMenu(array $column): Htmlable
    {
        $id = (string) $column['id'];
        $stored = $this->boardColumnOptions()->firstWhere('id', $id)['stored'] ?? null;

        return new HtmlString(view('filament.app.board-stage-menu', [
            'id' => $id,
            'name' => (string) $column['label'],
            'noun' => $this->boardStageNoun(),
            'dot' => ColorResolver::dotStyle($column['color'] ?? null),
            'swatches' => array_map(fn (OptionColor $color): array => [
                'value' => $color->value,
                'label' => $color->label(),
                'style' => ColorResolver::dotStyle($color->value),
                'current' => $stored !== null && strtolower($stored) === $color->value,
            ], OptionColor::cases()),
            'editable' => $id !== BoardStateData::NO_VALUE_COLUMN && $this->canEditStages(),
            'celebrate' => $this->boardColumnOptions()->firstWhere('id', $id)['celebrate'] ?? false,
            'locked' => $this->locksStage($id),
            'error' => $this->getErrorBag()->first("boardStageMenu.{$id}"),
        ])->render());
    }

    /**
     * @return list<Action>
     */
    protected function boardStageActions(): array
    {
        return [
            Action::make('collapseStage')
                ->label(fn (): string => __('filament/pages/boards.stage_menu.collapse', ['noun' => $this->boardStageNoun()]))
                ->icon('lucide-square-arrow-left')
                ->action(fn (array $arguments) => $this->setBoardColumnCollapsed($arguments['column'] ?? null, true)),
            Action::make('hideStage')
                ->label(fn (): string => __('filament/pages/boards.stage_menu.hide', ['noun' => $this->boardStageNoun()]))
                ->icon('lucide-eye-off')
                ->action(fn (array $arguments) => $this->hideStageFromMenu($arguments['column'] ?? null)),
            Action::make('deleteStage')
                ->label(fn (): string => __('filament/pages/boards.stage_menu.delete', ['noun' => $this->boardStageNoun()]))
                ->icon('lucide-trash-2')
                ->color('danger')
                ->visible(fn (array $arguments): bool => ($arguments['column'] ?? null) !== BoardStateData::NO_VALUE_COLUMN && $this->canEditStages() && ! $this->locksStage($arguments['column'] ?? null))
                ->modalIcon('lucide-trash-2')
                ->modalWidth(Width::Medium)
                ->modalHeading(fn (array $arguments): string => __('filament/pages/boards.stage_menu.delete_heading', ['name' => $this->stageNameFor($arguments['column'] ?? null)]))
                ->modalDescription(fn (array $arguments): string => $this->deleteStageDescription($arguments['column'] ?? null))
                ->modalSubmitActionLabel(__('filament/pages/boards.stage_menu.delete_confirm'))
                ->action(fn (array $arguments) => $this->deleteBoardColumn($arguments['column'] ?? null)),
        ];
    }

    private function locksStage(mixed $optionId): bool
    {
        return CustomFieldOptionPlan::locksOption($this->boardColumnField(), (string) $optionId);
    }

    private function stageNameFor(mixed $optionId): string
    {
        return is_string($optionId) ? (string) ($this->boardColumnOptions()->firstWhere('id', $optionId)['name'] ?? '') : '';
    }

    private function deleteStageDescription(mixed $optionId): string
    {
        $count = is_string($optionId) ? CustomFieldValue::query()->holdingOptionOnLiveRecord($this->boardColumnField(), $optionId)->count() : 0;

        if ($count === 0) {
            return '';
        }

        $entity = $this->viewSwitcherEntity();

        return trans_choice('filament/pages/boards.stage_menu.delete_body', $count, [
            'count' => Number::format($count),
            'records' => trans_choice('filament/pages/boards.stage_menu.records.'.$entity->value, $count),
            'attribute' => $this->boardStageNoun(),
            'column' => $this->noValueColumnLabel(),
        ]);
    }

    private function stageWriteFailure(ValidationException $exception, ?string $name = null): string
    {
        return match (true) {
            $exception instanceof OptionsChangedException => __('filament/pages/boards.stage_menu.changed', ['noun' => $this->boardStageNoun()]),
            $name !== null && $this->failedOnDistinctName($exception) => __('filament/pages/boards.stage_menu.name_taken', ['noun' => $this->boardStageNoun(), 'name' => $name]),
            default => (string) collect($exception->errors())->flatten()->first(),
        };
    }

    private function failedOnDistinctName(ValidationException $exception): bool
    {
        return collect($exception->validator->failed())->contains(fn (array $rules): bool => array_key_exists('Distinct', $rules));
    }

    private function deleteBoardColumn(mixed $optionId): void
    {
        if (! is_string($optionId) || ! $this->boardColumnOptions()->contains('id', $optionId)) {
            return;
        }

        $noun = $this->boardStageNoun();
        $field = $this->boardColumnField();

        if ($field->options->count() === 1) {
            Notification::make()->warning()->title(__('filament/pages/boards.stage_menu.delete_only', ['noun' => $noun]))->send();

            return;
        }

        if ($this->visibleBoardColumnIds() === [$optionId]) {
            Notification::make()->warning()->title(__('filament/pages/boards.stage_menu.delete_last_visible', ['noun' => $noun]))->send();

            return;
        }

        $options = array_values(array_filter($this->stageOptionEntries($field), static fn (array $entry): bool => $entry['id'] !== $optionId));

        try {
            resolve(SetCustomFieldOptions::class)->execute($this->viewSwitcherUser(), $field, ['options' => $options, 'replacements' => [$optionId => null], 'removed' => [$optionId]], capsClears: false);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title($this->stageWriteFailure($exception))->send();

            return;
        }

        $this->refreshBoardAfterStageWrite();
    }

    protected function addBoardStage(string $name, ?string $color = null): void
    {
        $name = trim($name);
        $field = $this->boardColumnField();
        $options = [...$this->stageOptionEntries($field), ['name' => $name, 'color' => $color ?? OptionColor::Gray->value]];

        try {
            resolve(SetCustomFieldOptions::class)->execute($this->viewSwitcherUser(), $field, ['options' => $options, 'removed' => []], keepsColors: $color === null);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['name' => $this->stageWriteFailure($exception, $name)]);
        }

        $this->refreshBoardAfterStageWrite();
    }

    protected function addBoardStageUsing(): ?Closure
    {
        return $this->canEditStages() ? fn (string $name, ?string $color) => $this->addBoardStage($name, $color) : null;
    }

    /**
     * @return array<string, string>
     */
    protected function boardStageColors(): array
    {
        return collect(OptionColor::cases())->mapWithKeys(fn (OptionColor $color): array => [$color->value => $color->label()])->all();
    }

    private function hideStageFromMenu(mixed $optionId): void
    {
        if (is_string($optionId) && $this->visibleBoardColumnIds() === [$optionId]) {
            Notification::make()->warning()->title(__('filament/pages/boards.stage_menu.last_visible', ['noun' => $this->boardStageNoun()]))->send();

            return;
        }

        $this->setBoardColumnVisible($optionId, false);
    }

    private function canEditStages(): bool
    {
        $user = $this->viewSwitcherUser();

        return $user->hasWorkspaceCapability($user->currentWorkspace?->getKey(), WorkspaceCapability::FieldsManage);
    }

    private function boardStageNoun(): string
    {
        $entity = $this->viewSwitcherEntity();
        $key = $this->boardColumnField()->code === $entity->boardFieldCode() ? $entity->boardStageNounKey() : null;

        return __('filament/pages/boards.stage_menu.nouns.'.($key ?? 'option'));
    }

    public function boardLayoutToggles(): Htmlable
    {
        $state = $this->storedBoardState();

        return new HtmlString(view('filament.app.board-layout-toggles', [
            'columnsCollapsed' => $state->columnsCollapsed,
            'cardSize' => $state->cardSize,
        ])->render());
    }

    public function boardViewSettings(): Htmlable
    {
        $field = $this->boardColumnField();

        $visible = $this->visibleBoardColumnIds();

        return new HtmlString(view('filament.app.board-view-settings', [
            'columns' => $this->boardColumnOptions()
                ->map(static fn (array $option): array => [...$option, 'visible' => in_array($option['id'], $visible, true)])
                ->all(),
            'visibleCount' => count($visible) + ($this->showsNoValueColumn() ? 1 : 0),
            'visibleRealCount' => count($visible),
            'noValueColumn' => ['label' => $this->noValueColumnLabel(), 'on' => $this->showsNoValueColumn()],
            'groupings' => $this->boardGroupings(),
            'grouping' => $this->getBoard()->getActiveGrouping()?->getName(),
            'columnFields' => $this->boardColumnFields()
                ->map(static fn (CustomField $candidate): array => [
                    'code' => $candidate->code,
                    'name' => $candidate->name,
                    'icon' => CustomFieldType::from($candidate->type)->iconPath(),
                ])
                ->values()
                ->all(),
            'columnField' => $field->code,
            'columnFieldName' => $field->name,
            ...$this->cardSettingsView(),
        ])->render());
    }

    /**
     * @return Collection<string, CustomField>
     */
    protected function boardColumnFields(): Collection
    {
        return $this->boardColumnFieldList ??= resolve(BoardColumnFieldsQuery::class)->get(
            $this->viewSwitcherUser(),
            $this->viewSwitcherWorkspace(),
            $this->viewSwitcherEntity(),
        );
    }

    protected function boardColumnField(): CustomField
    {
        return $this->resolvedBoardColumnField ??= $this->resolveBoardColumnField();
    }

    private function resolveBoardColumnField(): CustomField
    {
        $stored = $this->storedBoardState()->columnField;

        return ($stored === null ? null : $this->boardColumnFields()->get($stored))
            ?? $this->defaultBoardColumnField()
            ?? throw new LogicException('The board has no field to make columns from.');
    }

    /**
     * @return Collection<int, array{id: string, name: string, color: string, stored: ?string, celebrate: bool}>
     */
    protected function boardColumnOptions(): Collection
    {
        return $this->resolvedBoardColumnOptions ??= $this->resolveBoardColumnOptions();
    }

    /**
     * @return Collection<int, array{id: string, name: string, color: string, stored: ?string, celebrate: bool}>
     */
    private function resolveBoardColumnOptions(): Collection
    {
        $field = $this->boardColumnField();
        $colorsEnabled = $field->settings->enable_option_colors ?? false;

        $options = $field->options->map(static fn (CustomFieldOption $option): array => [
            'id' => (string) $option->getKey(),
            'name' => (string) $option->getAttribute('name'),
            'color' => $colorsEnabled ? ($option->settings->color ?? 'gray') : 'gray',
            'stored' => $option->settings->color,
            'celebrate' => (bool) $option->celebrates(),
        ]);

        $position = array_flip($this->storedBoardState()->orderStages(array_column($options->all(), 'id')));

        return $options
            ->sortBy(static fn (array $option): int|false => $position[$option['id']] ?? false)
            ->values();
    }

    /**
     * @return list<string>
     */
    protected function hiddenBoardColumnIds(): array
    {
        $ids = array_values($this->boardColumnOptions()->pluck('id')->all());
        $hidden = array_values(array_intersect($ids, $this->storedBoardState()->hiddenColumns));

        return count($hidden) === count($ids) ? [] : $hidden;
    }

    /**
     * @return list<string>
     */
    protected function visibleBoardColumnIds(): array
    {
        $hidden = $this->hiddenBoardColumnIds();

        return array_values($this->boardColumnOptions()
            ->pluck('id')
            ->reject(static fn (string $id): bool => in_array($id, $hidden, true))
            ->all());
    }

    protected function showsNoValueColumn(): bool
    {
        return in_array($this->boardColumnField()->code, $this->storedBoardState()->noValueColumns, true);
    }

    protected function boardColumnIdentifier(): string
    {
        return $this->showsNoValueColumn() ? 'board_column' : $this->boardColumnField()->getValueColumn();
    }

    protected function boardColumnExpression(): string
    {
        $identifier = $this->boardColumnIdentifier();

        return $this->showsNoValueColumn() ? $identifier : "cfv.{$identifier}";
    }

    protected function columnValueFor(string $columnId): ?string
    {
        return $columnId === BoardStateData::NO_VALUE_COLUMN ? null : $columnId;
    }

    protected function ownsColumn(string $id): bool
    {
        return in_array($id, $this->visibleBoardColumnIds(), true)
            || ($id === BoardStateData::NO_VALUE_COLUMN && $this->showsNoValueColumn());
    }

    private function noValueColumnLabel(): string
    {
        return __('filament/pages/boards.view_settings.no_value_column', ['attribute' => $this->boardColumnField()->name]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function boardColumnQuery(Builder $query, CustomField $field): Builder
    {
        $table = $query->getModel()->getTable();
        $valueColumn = $field->getValueColumn();

        if (! $this->showsNoValueColumn()) {
            return $query
                ->leftJoin('custom_field_values as cfv', function (JoinClause $join) use ($table, $field): void {
                    $join->on("{$table}.id", '=', 'cfv.entity_id')->where('cfv.custom_field_id', '=', $field->getKey());
                })
                ->select("{$table}.*", "cfv.{$valueColumn}");
        }

        $values = CustomFieldValue::query()->withoutGlobalScopes()->toBase()
            ->select('entity_id', "{$valueColumn} as board_value")
            ->where('custom_field_id', $field->getKey());

        $eagerLoads = $query->getEagerLoads();
        $query->setEagerLoads([])
            ->leftJoinSub($values, 'cfv', "{$table}.id", '=', 'cfv.entity_id')
            ->select("{$table}.*")
            ->getQuery()
            ->selectRaw('coalesce(cfv.board_value::text, ?) as board_column', [BoardStateData::NO_VALUE_COLUMN]);

        return $query->getModel()->newQuery()->fromSub($query, $table)->select("{$table}.*")->setEagerLoads($eagerLoads);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function withoutHiddenColumnCards(Builder $query): Builder
    {
        $hidden = $this->hiddenBoardColumnIds();
        $column = $this->boardColumnExpression();

        return $hidden === []
            ? $query
            : $query->where(static fn (Builder $inner): Builder => $inner->whereNull($column)->orWhereNotIn($column, $hidden));
    }

    /**
     * @return list<Column>
     */
    protected function boardColumns(): array
    {
        $hidden = $this->hiddenBoardColumnIds();

        $columns = $this->boardColumnOptions()
            ->map(static fn (array $option): Column => Column::make($option['id'])
                ->color($option['color'])
                ->label($option['name'])
                ->hidden(in_array($option['id'], $hidden, true)))
            ->all();

        return array_values($this->showsNoValueColumn()
            ? [Column::make(BoardStateData::NO_VALUE_COLUMN)->label($this->noValueColumnLabel()), ...$columns]
            : $columns);
    }

    /**
     * @param  list<Action|ActionGroup>  $actions
     * @return list<Action|ActionGroup>
     */
    protected function prefillingFirstVisibleColumn(array $actions): array
    {
        return array_map(
            fn (Action|ActionGroup $action): Action|ActionGroup => $action instanceof CreateAction
                ? $action
                    ->mountUsing(fn (?Schema $schema) => $this->fillWithFirstVisibleColumn($schema))
                    ->after(fn (Model $record) => $this->placeAtBottomOfItsColumn($record))
                : $action,
            $actions,
        );
    }

    protected function placeNewCard(Model $card, string $columnId, string $edge = 'top'): void
    {
        resolve(PlaceBoardCard::class)->execute($this->viewSwitcherUser(), $card, $this->getBoard()->getPositionIdentifierAttribute(), $this->getBoardPositionInColumn($columnId, $edge));
    }

    protected function authorizeCardMove(Model $card, string $targetColumnId, ?string $toLaneId = null): void
    {
        $this->refuseReorderWhileSorted($card, $targetColumnId, $toLaneId);

        if (Gate::denies('update', $card)) {
            throw CardMoveRejectedException::because(__('filament/pages/boards.move_denied'));
        }
    }

    protected function persistCardMove(Model $card, mixed $columnValue, string $position): void
    {
        abort_unless($card instanceof HasCustomFields, 422);

        resolve(MoveBoardCard::class)->execute(
            $this->viewSwitcherUser(),
            $card,
            $this->getBoard()->getPositionIdentifierAttribute(),
            $position,
            $this->boardColumnField(),
            $this->columnValueFor((string) $columnValue),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createCard(array $data, CreateAction $action): Model&HasCustomFields
    {
        $columnId = $action->getArguments()['column'] ?? null;

        abort_unless(blank($columnId) || $this->ownsColumn((string) $columnId), 422);

        $card = $this->createBoardRecord($this->viewSwitcherUser()->currentWorkspace, $data);

        if (filled($columnId)) {
            if ($columnId !== BoardStateData::NO_VALUE_COLUMN) {
                $card->saveCustomFieldValue($this->boardColumnField(), $columnId);
            }

            $this->placeNewCard($card, $columnId);
        }

        return $card;
    }

    private function fillWithFirstVisibleColumn(?Schema $schema): void
    {
        if (! $schema instanceof Schema) {
            return;
        }

        $schema->fill();

        $state = $schema->getRawState();
        data_set($state, 'custom_fields.'.$this->boardColumnField()->code, $this->visibleBoardColumnIds()[0] ?? null);
        $schema->rawState($state);
    }

    private function placeAtBottomOfItsColumn(Model $record): void
    {
        if (! $record instanceof HasCustomFields) {
            return;
        }

        $field = $this->boardColumnField();
        $value = $record->customFieldValues()->where('custom_field_id', $field->getKey())->value($field->getValueColumn());

        $columnId = match (true) {
            is_string($value) && $this->boardColumnOptions()->contains('id', $value) => $value,
            blank($value) && $this->showsNoValueColumn() => BoardStateData::NO_VALUE_COLUMN,
            default => null,
        };

        if ($columnId !== null) {
            $this->placeNewCard($record, $columnId, 'bottom');
        }
    }

    abstract protected function defaultBoardColumnField(): ?CustomField;

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function createBoardRecord(Workspace $workspace, array $data): Model&HasCustomFields;

    abstract protected function refuseReorderWhileSorted(Model $card, string $targetColumnId, ?string $toLaneId = null): void;

    /**
     * @return array<string, mixed>
     */
    abstract protected function cardSettingsView(): array;

    /**
     * @return list<array{id: string, label: string, icon: ?string}>
     */
    abstract protected function boardGroupings(): array;

    abstract protected function storedBoardState(): BoardStateData;

    abstract protected function boardStateWritten(BoardStateData $state): void;

    abstract protected function activeBoardView(): ?SavedView;

    /**
     * @param  Closure(?SavedView): BoardStateData  $write
     */
    abstract protected function writingBoardState(Closure $write): ?BoardStateData;
}
